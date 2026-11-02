<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Disc\Chd;

use Boquizo\RaHasher\Compression\FlacDecoder;
use Boquizo\RaHasher\Compression\LzmaDecoder;
use Boquizo\RaHasher\Compression\ZstdDecoder;
use RuntimeException;
use SplFileInfo;
use SplFileObject;

/**
 * A CHD v5 image, read from disk a hunk at a time like libchdr does. Only
 * images without a parent are supported, and only hunks compressed with
 * zlib, lzma, Zstandard or the CD codecs (cdzl, cdlz, cdzs, cdfl) can be
 * decoded: plain FLAC and Huffman hunks throw when read.
 */
final class ChdFile
{
    private const string MAGIC = 'MComprHD';

    private const int HEADER_BYTES = 124;

    private const int VERSION = 5;

    private const int MAP_HEADER_BYTES = 16;

    private const int METADATA_HEADER_BYTES = 16;

    private const int METADATA_LENGTH_MASK = 0xFFFFFF;

    private const string NO_COMPRESSOR = "\0\0\0\0";

    /** The hunk sizes libchdr accepts are below this, CHD_MAX_HUNK_SIZE. */
    private const int MAX_HUNK_BYTES = 128 * 1024 * 1024;

    /** The most bytes the map records of an image may take, libchdr's CHD_MAX_MAP_SIZE. */
    private const int MAX_MAP_BYTES = 256 * 1024 * 1024;

    private const int COMPRESSED_RECORD_BYTES = 12;

    private const int UNCOMPRESSED_RECORD_BYTES = 4;

    /** A raw CD frame: a 2352-byte sector followed by 96 bytes of subcode. */
    private const int CD_FRAME_BYTES = 2448;

    private const int CD_SUBCODE_BYTES = 96;

    /** The 16-bit stereo samples of a CD audio sector. */
    private const int CD_SAMPLES_PER_SECTOR = CdSectorEcc::SECTOR_BYTES / 4;

    private ?int $cachedHunk = null;

    private string $cachedData = '';

    /** @var list<array{tag: string, offset: int, length: int}>|null the tag, data offset and data length of every metadata entry, read on first use */
    private ?array $metadataEntries = null;

    /**
     * @param  list<string>  $compressors  the FourCC of the codec of every hunk type from 0 to 3
     * @param  positive-int  $hunkBytes
     */
    private function __construct(
        private readonly SplFileObject $file,
        private readonly int $fileSize,
        private readonly array $compressors,
        private readonly ChdMap $map,
        private readonly int $metadataOffset,
        private readonly int $hunkBytes,
        private readonly int $unitBytes,
        private readonly int $unitCount,
    ) {}

    public static function open(SplFileInfo|string $path): self
    {
        $file = $path instanceof SplFileInfo ? $path->openFile('rb') : new SplFileObject($path, 'rb');
        $fileSize = (int) $file->getSize();
        $header = (string) $file->fread(self::HEADER_BYTES);

        if (! str_starts_with($header, self::MAGIC) || mb_strlen($header, '8bit') < 16) {
            throw new RuntimeException('The file is not a CHD image.');
        }

        /** @var array{length: int, version: int} $prefix */
        $prefix = unpack('x8/Nlength/Nversion', $header);

        if ($prefix['version'] !== self::VERSION) {
            throw new RuntimeException('CHD images of version ' . $prefix['version'] . ' are not supported.');
        }

        if ($prefix['length'] !== self::HEADER_BYTES || mb_strlen($header, '8bit') < self::HEADER_BYTES) {
            throw new RuntimeException('The CHD v5 header is truncated.');
        }

        /** @var array{compressor0: string, compressor1: string, compressor2: string, compressor3: string, logicalBytes: int, mapOffset: int, metadataOffset: int, hunkBytes: int<0, max>, unitBytes: int<0, max>, parentSha1: string} $fields */
        $fields = unpack('x16/a4compressor0/a4compressor1/a4compressor2/a4compressor3/JlogicalBytes/JmapOffset/JmetadataOffset/NhunkBytes/NunitBytes/x40/a20parentSha1', $header);

        if ($fields['parentSha1'] !== str_repeat("\0", 20)) {
            throw new RuntimeException('CHD images with a parent are not supported.');
        }

        if ($fields['hunkBytes'] === 0 || $fields['unitBytes'] === 0) {
            throw new RuntimeException('The CHD image has hunks or units of zero bytes.');
        }

        if ($fields['logicalBytes'] <= 0) {
            throw new RuntimeException('The CHD image has no logical data.');
        }

        if ($fields['hunkBytes'] >= self::MAX_HUNK_BYTES) {
            throw new RuntimeException('The CHD hunks of ' . $fields['hunkBytes'] . ' bytes are too big.');
        }

        if ($fields['mapOffset'] < 0 || $fields['mapOffset'] > $fileSize || $fields['metadataOffset'] < 0 || $fields['metadataOffset'] > $fileSize) {
            throw new RuntimeException('The CHD image points past the end of its file.');
        }

        $compressors = [$fields['compressor0'], $fields['compressor1'], $fields['compressor2'], $fields['compressor3']];
        $recordBytes = $compressors[0] === self::NO_COMPRESSOR ? self::UNCOMPRESSED_RECORD_BYTES : self::COMPRESSED_RECORD_BYTES;
        $hunkCount = self::unitsHolding($fields['logicalBytes'], $fields['hunkBytes']);

        if ($hunkCount > intdiv(self::MAX_MAP_BYTES, $recordBytes)) {
            throw new RuntimeException('The CHD map of ' . $hunkCount . ' hunks is too big.');
        }

        $map = $compressors[0] === self::NO_COMPRESSOR
            ? ChdMap::uncompressed(self::readAt($file, $fileSize, $fields['mapOffset'], $hunkCount * self::UNCOMPRESSED_RECORD_BYTES), $hunkCount, $fields['hunkBytes'])
            : self::compressedMap($file, $fileSize, $fields['mapOffset'], $hunkCount, $fields['hunkBytes']);

        return new self(
            $file,
            $fileSize,
            $compressors,
            $map,
            $fields['metadataOffset'],
            $fields['hunkBytes'],
            $fields['unitBytes'],
            self::unitsHolding($fields['logicalBytes'], $fields['unitBytes']),
        );
    }

    public function hunkBytes(): int
    {
        return $this->hunkBytes;
    }

    public function unitBytes(): int
    {
        return $this->unitBytes;
    }

    public function hunkCount(): int
    {
        return $this->map->count();
    }

    public function unitCount(): int
    {
        return $this->unitCount;
    }

    /**
     * The data of the index-th metadata entry with the tag, without its
     * trailing NULs, or null when there is no such entry.
     */
    public function metadata(string $tag, int $index): ?string
    {
        foreach ($this->metadataEntries() as $entry) {
            if ($entry['tag'] !== $tag) {
                continue;
            }

            if ($index === 0) {
                return mb_rtrim($this->read($entry['offset'], $entry['length']), "\0", '8bit');
            }

            $index--;
        }

        return null;
    }

    /**
     * The hunk-size bytes of a hunk, checked against its CRC when the map
     * has one. The last hunk read is kept, as sectors are read in order.
     */
    public function readHunk(int $hunk): string
    {
        if ($hunk === $this->cachedHunk) {
            return $this->cachedData;
        }

        $entry = $this->map->entry($hunk);

        $data = match ($entry->type) {
            ChdMapEntry::ZERO => str_repeat("\0", $this->hunkBytes),
            ChdMapEntry::UNCOMPRESSED => $this->read($entry->offset, $this->hunkBytes),
            ChdMapEntry::SELF => $this->readHunk($this->map->resolve($hunk)),
            default => $this->decompress($hunk, $this->compressors[$entry->type], $this->read($entry->offset, $entry->length)),
        };

        if ($entry->crc !== null && Crc16::compute($data) !== $entry->crc) {
            throw new RuntimeException('Hunk ' . $hunk . ' of the CHD image does not match its CRC.');
        }

        $this->cachedHunk = $hunk;
        $this->cachedData = $data;

        return $data;
    }

    /**
     * How many units of the size hold the bytes, without overflowing.
     *
     * @param  positive-int  $bytes
     * @param  positive-int  $unitSize
     */
    private static function unitsHolding(int $bytes, int $unitSize): int
    {
        return intdiv($bytes, $unitSize) + ($bytes % $unitSize === 0 ? 0 : 1);
    }

    private static function compressedMap(SplFileObject $file, int $fileSize, int $mapOffset, int $hunkCount, int $hunkBytes): ChdMap
    {
        /** @var array{1: int} $mapBytes */
        $mapBytes = unpack('N', self::readAt($file, $fileSize, $mapOffset, self::MAP_HEADER_BYTES));

        return ChdMap::compressed(self::readAt($file, $fileSize, $mapOffset, self::MAP_HEADER_BYTES + $mapBytes[1]), $hunkCount, $hunkBytes);
    }

    /**
     * Raw deflate data that must inflate to exactly the given size.
     * gzinflate warns on corrupt data besides returning false, so that warning
     * is silenced while it runs (test runners report it even behind an @).
     */
    private static function inflate(string $compressed, int $size): string
    {
        set_error_handler(static fn (): bool => true);

        try {
            $data = gzinflate($compressed, $size);
        } finally {
            restore_error_handler();
        }

        if ($data === false || mb_strlen($data, '8bit') !== $size) {
            throw new RuntimeException('The deflate data of the CHD image is corrupt.');
        }

        return $data;
    }

    /**
     * Bytes that all lie inside the file: a range that does not, however
     * the file is cut or the range overflows, is never handed to fread,
     * which allocates what it is asked for.
     */
    private static function readAt(SplFileObject $file, int $fileSize, int $offset, int $length): string
    {
        if ($length === 0) {
            return '';
        }

        if ($offset < 0 || $length < 0 || $offset > $fileSize || $length > $fileSize - $offset || $file->fseek($offset) === -1) {
            throw new RuntimeException('The CHD image ends before its data does.');
        }

        $data = (string) $file->fread($length);

        if (mb_strlen($data, '8bit') !== $length) {
            throw new RuntimeException('The CHD image ends before its data does.');
        }

        return $data;
    }

    /**
     * The FLAC block size chdman encodes a cdfl hunk with, like libchdr's
     * cdfl_codec_blocksize: the hunk's stereo samples halved until they
     * fit in a sector.
     */
    private static function cdFlacBlockSize(int $sampleFrames): int
    {
        $blockSize = $sampleFrames;

        while ($blockSize > CdSectorEcc::SECTOR_BYTES) {
            $blockSize = intdiv($blockSize, 2);
        }

        return $blockSize;
    }

    private function decompress(int $hunk, string $codec, string $compressed): string
    {
        return match ($codec) {
            'zlib' => self::inflate($compressed, $this->hunkBytes),
            'lzma' => LzmaDecoder::decode($compressed, $this->hunkBytes),
            'zstd' => ZstdDecoder::decode($compressed, $this->hunkBytes),
            'cdzl', 'cdlz', 'cdzs' => $this->decompressCd($compressed, $codec),
            'cdfl' => $this->decompressCdFlac($compressed),
            default => throw new RuntimeException('Hunk ' . $hunk . ' of the CHD image uses the unsupported codec "' . mb_rtrim($codec, "\0", '8bit') . '".'),
        };
    }

    /**
     * A hunk of the CD codecs: a bitmap of the sectors whose sync and ECC
     * were dropped, the length of the compressed sectors (2 bytes, 3 for
     * hunks of 64 KiB or more), the sectors compressed with zlib, lzma or
     * zstd and then the subcode, compressed with zstd in cdzs and with zlib
     * otherwise. Frames interleave them again.
     */
    private function decompressCd(string $compressed, string $codec): string
    {
        $frameCount = $this->cdFrameCount();
        $eccBytes = ($frameCount + 7) >> 3;
        $headerBytes = $eccBytes + ($this->hunkBytes < 65536 ? 2 : 3);
        $compressedBytes = mb_strlen($compressed, '8bit');

        if ($compressedBytes < $headerBytes) {
            throw new RuntimeException('The CD hunk of the CHD image is too short for its header.');
        }

        $sectorsBytes = 0;

        for ($position = $eccBytes; $position < $headerBytes; $position++) {
            $sectorsBytes = ($sectorsBytes << 8) | ord($compressed[$position]);
        }

        if ($compressedBytes < $headerBytes + $sectorsBytes) {
            throw new RuntimeException('The CD hunk of the CHD image is shorter than its sectors.');
        }

        $compressedSectors = mb_substr($compressed, $headerBytes, $sectorsBytes, '8bit');
        $sectors = match ($codec) {
            'cdlz' => LzmaDecoder::decode($compressedSectors, $frameCount * CdSectorEcc::SECTOR_BYTES),
            'cdzs' => ZstdDecoder::decode($compressedSectors, $frameCount * CdSectorEcc::SECTOR_BYTES),
            default => self::inflate($compressedSectors, $frameCount * CdSectorEcc::SECTOR_BYTES),
        };
        $compressedSubcode = mb_substr($compressed, $headerBytes + $sectorsBytes, null, '8bit');
        $subcode = $codec === 'cdzs'
            ? ZstdDecoder::decode($compressedSubcode, $frameCount * self::CD_SUBCODE_BYTES)
            : self::inflate($compressedSubcode, $frameCount * self::CD_SUBCODE_BYTES);
        $hunk = '';

        for ($frame = 0; $frame < $frameCount; $frame++) {
            $sector = mb_substr($sectors, $frame * CdSectorEcc::SECTOR_BYTES, CdSectorEcc::SECTOR_BYTES, '8bit');

            if ((ord($compressed[$frame >> 3]) & (1 << ($frame & 7))) !== 0) {
                $sector = CdSectorEcc::regenerate($sector);
            }

            $hunk .= $sector . mb_substr($subcode, $frame * self::CD_SUBCODE_BYTES, self::CD_SUBCODE_BYTES, '8bit');
        }

        return $hunk;
    }

    /**
     * A hunk of the cdfl codec: the sectors as the 16-bit stereo samples of
     * FLAC frames, then the subcode compressed with zlib. There is no ECC
     * bitmap, so the sectors are stored whole.
     */
    private function decompressCdFlac(string $compressed): string
    {
        $frameCount = $this->cdFrameCount();
        $sampleFrames = $frameCount * self::CD_SAMPLES_PER_SECTOR;
        $flac = FlacDecoder::decodeStereo16($compressed, $sampleFrames, self::cdFlacBlockSize($sampleFrames));
        $subcode = self::inflate(mb_substr($compressed, $flac['consumedBytes'], null, '8bit'), $frameCount * self::CD_SUBCODE_BYTES);
        $hunk = '';

        for ($frame = 0; $frame < $frameCount; $frame++) {
            $hunk .= mb_substr($flac['samples'], $frame * CdSectorEcc::SECTOR_BYTES, CdSectorEcc::SECTOR_BYTES, '8bit')
                . mb_substr($subcode, $frame * self::CD_SUBCODE_BYTES, self::CD_SUBCODE_BYTES, '8bit');
        }

        return $hunk;
    }

    /**
     * The CD frames of a hunk, which the CD codecs need whole.
     *
     * @return positive-int
     */
    private function cdFrameCount(): int
    {
        $frameCount = intdiv($this->hunkBytes, self::CD_FRAME_BYTES);

        if ($frameCount < 1 || $frameCount * self::CD_FRAME_BYTES !== $this->hunkBytes) {
            throw new RuntimeException('The CHD hunks of ' . $this->hunkBytes . ' bytes do not hold whole CD frames.');
        }

        return $frameCount;
    }

    private function read(int $offset, int $length): string
    {
        return self::readAt($this->file, $this->fileSize, $offset, $length);
    }

    /**
     * The metadata entries, found by following the chain from the first one
     * once. An entry that was already visited makes the chain loop.
     *
     * @return list<array{tag: string, offset: int, length: int}>
     */
    private function metadataEntries(): array
    {
        if ($this->metadataEntries !== null) {
            return $this->metadataEntries;
        }

        $entries = [];
        $visited = [];
        $offset = $this->metadataOffset;

        while ($offset !== 0) {
            if (isset($visited[$offset])) {
                throw new RuntimeException('The metadata of the CHD image loops.');
            }

            $visited[$offset] = true;

            /** @var array{tag: string, flagsAndLength: int, next: int} $header */
            $header = unpack('a4tag/NflagsAndLength/Jnext', $this->read($offset, self::METADATA_HEADER_BYTES));
            $entries[] = ['tag' => $header['tag'], 'offset' => $offset + self::METADATA_HEADER_BYTES, 'length' => $header['flagsAndLength'] & self::METADATA_LENGTH_MASK];
            $offset = $header['next'];
        }

        return $this->metadataEntries = $entries;
    }
}
