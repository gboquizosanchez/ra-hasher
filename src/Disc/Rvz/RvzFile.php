<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Disc\Rvz;

use Boquizo\RaHasher\Compression\ZstdDecoder;
use Boquizo\RaHasher\Disc\Nintendo\NintendoDisc;
use Override;
use RuntimeException;
use SplFileInfo;
use SplFileObject;

/**
 * A disc in Dolphin's RVZ format (docs/WiaAndRvz.md, DiscIO/WIABlob.cpp):
 * the disc is cut into groups of chunk-size bytes, each one stored as is,
 * compressed with Zstandard or all zeros, and optionally RVZ packed.
 *
 * Only the raw data is read, which is the whole of a GameCube disc. Wii
 * partitions are stored decrypted and would have to be encrypted again, so
 * reading them throws, as does reading what no raw data covers (Dolphin
 * fails there too). Only groups without compression or with Zstandard can be
 * decoded; the bzip2, LZMA and LZMA2 methods of WIA throw on open. The SHA-1
 * hashes of the headers are not checked: a damaged file fails to decode.
 */
final class RvzFile implements NintendoDisc
{
    private const string MAGIC = "RVZ\x01";

    private const int FILE_HEAD_BYTES = 0x48;

    /** The wia_disc_t struct up to its compressor data. */
    private const int DISC_BYTES = 0xDC;

    /** The first bytes of the disc, which the disc struct holds. */
    private const int DISC_HEADER_BYTES = 0x80;

    private const int NO_COMPRESSION = 0;

    private const int ZSTANDARD = 5;

    private const int RAW_DATA_BYTES = 0x18;

    private const int GROUP_BYTES = 0x0C;

    /** Raw data is laid out from the start of the 32 KiB block it starts in. */
    private const int BLOCK_BYTES = 0x8000;

    /** Chunks larger than 2 MiB must be multiples of it. */
    private const int WII_GROUP_BYTES = 0x200000;

    private const int COMPRESSED_FLAG = 0x80000000;

    private ?int $cachedGroup = null;

    private string $cachedData = '';

    /**
     * @param  list<array{start: int, end: int, blockStart: int, firstGroup: int, groupCount: int}>  $rawData  where every raw data entry starts and ends on the disc, the start of its first block and its groups
     * @param  list<array{offset: int, storedBytes: int, isCompressed: bool, packedBytes: int}>  $groups  where the data of every group is in the file, its size there, whether it is compressed and its size once decompressed when it is RVZ packed (0 otherwise)
     * @param  positive-int  $chunkBytes
     */
    private function __construct(
        private readonly SplFileObject $file,
        private readonly int $fileSize,
        private readonly int $discSize,
        private readonly string $discHeader,
        private readonly int $compression,
        private readonly int $chunkBytes,
        private readonly array $rawData,
        private readonly array $groups,
    ) {}

    public static function open(SplFileInfo $path): self
    {
        $file = $path->openFile('rb');

        /** @var array{size: int} $stat */
        $stat = $file->fstat();
        $fileSize = $stat['size'];
        $head = self::readAt($file, $fileSize, 0, min($fileSize, self::FILE_HEAD_BYTES + self::DISC_BYTES));

        if (! str_starts_with($head, self::MAGIC) || mb_strlen($head, '8bit') < self::FILE_HEAD_BYTES + self::DISC_BYTES) {
            throw new RuntimeException('The file is not an RVZ disc image.');
        }

        /** @var array{discSize: int, compression: int, chunkBytes: int, rawDataCount: int, rawDataOffset: int, rawDataStored: int, groupCount: int, groupOffset: int, groupStored: int} $fields */
        $fields = unpack('x36/JdiscSize/x28/x4/Ncompression/x4/NchunkBytes/x128/x36/NrawDataCount/JrawDataOffset/NrawDataStored/NgroupCount/JgroupOffset/NgroupStored', $head);

        if ($fields['compression'] !== self::NO_COMPRESSION && $fields['compression'] !== self::ZSTANDARD) {
            throw new RuntimeException('RVZ images compressed with method ' . $fields['compression'] . ' are not supported.');
        }

        $chunkBytes = $fields['chunkBytes'];
        $isPowerOfTwo = ($chunkBytes & ($chunkBytes - 1)) === 0;

        if ($chunkBytes < self::BLOCK_BYTES || (! $isPowerOfTwo && $chunkBytes % self::WII_GROUP_BYTES !== 0)) {
            throw new RuntimeException('The RVZ chunks of ' . $chunkBytes . ' bytes are not valid.');
        }

        $rawDataTable = self::decodeTable($file, $fileSize, $fields['compression'], $fields['rawDataOffset'], $fields['rawDataStored'], $fields['rawDataCount'] * self::RAW_DATA_BYTES);
        $groupTable = self::decodeTable($file, $fileSize, $fields['compression'], $fields['groupOffset'], $fields['groupStored'], $fields['groupCount'] * self::GROUP_BYTES);

        return new self(
            $file,
            $fileSize,
            $fields['discSize'],
            mb_substr($head, self::FILE_HEAD_BYTES + 0x10, self::DISC_HEADER_BYTES, '8bit'),
            $fields['compression'],
            $chunkBytes,
            self::rawDataEntries($rawDataTable),
            self::groupEntries($groupTable),
        );
    }

    #[Override]
    public function read(int $offset, int $length): string
    {
        if ($offset < 0 || $length < 0 || $offset > $this->discSize || $length > $this->discSize - $offset) {
            throw new RuntimeException('The RVZ disc ends before the data it points to.');
        }

        $data = '';

        if ($offset < self::DISC_HEADER_BYTES && $length > 0) {
            $data = mb_substr($this->discHeader, $offset, min($length, self::DISC_HEADER_BYTES - $offset), '8bit');
            $offset += mb_strlen($data, '8bit');
            $length -= mb_strlen($data, '8bit');
        }

        while ($length > 0) {
            $entry = $this->rawDataAt($offset);
            $groupInEntry = intdiv($offset - $entry['blockStart'], $this->chunkBytes);
            $groupStart = $entry['blockStart'] + $groupInEntry * $this->chunkBytes;

            if ($groupInEntry >= $entry['groupCount']) {
                throw new RuntimeException('The RVZ raw data has fewer groups than it covers.');
            }

            $groupBytes = min($this->chunkBytes, $entry['end'] - $groupStart);
            $groupData = $this->readGroup($entry['firstGroup'] + $groupInEntry, $groupStart - $entry['blockStart'], $groupBytes);
            $chunk = mb_substr($groupData, $offset - $groupStart, min($length, $groupStart + $groupBytes - $offset), '8bit');

            $data .= $chunk;
            $offset += mb_strlen($chunk, '8bit');
            $length -= mb_strlen($chunk, '8bit');
        }

        return $data;
    }

    #[Override]
    public function size(): int
    {
        return $this->discSize;
    }

    /**
     * Bytes that all lie inside the file: a range that does not is never
     * handed to fread, which allocates what it is asked for.
     */
    private static function readAt(SplFileObject $file, int $fileSize, int $offset, int $length): string
    {
        if ($offset < 0 || $length < 0 || $offset > $fileSize || $length > $fileSize - $offset) {
            throw new RuntimeException('The RVZ image ends before its data does.');
        }

        if ($length === 0) {
            return '';
        }

        $file->fseek($offset);

        return (string) $file->fread($length);
    }

    /** A table of the file, stored with the compression method of the disc. */
    private static function decodeTable(SplFileObject $file, int $fileSize, int $compression, int $offset, int $storedBytes, int $bytes): string
    {
        $stored = self::readAt($file, $fileSize, $offset, $storedBytes);
        $table = $compression === self::ZSTANDARD ? ZstdDecoder::decode($stored, $bytes) : $stored;

        if (mb_strlen($table, '8bit') !== $bytes) {
            throw new RuntimeException('An RVZ table does not hold all its entries.');
        }

        return $table;
    }

    /**
     * The raw data entries that cover some of the disc, in the order they
     * are stored, which is the order of the disc.
     *
     * @return list<array{start: int, end: int, blockStart: int, firstGroup: int, groupCount: int}>
     */
    private static function rawDataEntries(string $table): array
    {
        $entries = [];

        foreach ($table === '' ? [] : mb_str_split($table, self::RAW_DATA_BYTES, '8bit') as $record) {
            /** @var array{start: int, size: int, firstGroup: int, groupCount: int} $fields */
            $fields = unpack('Jstart/Jsize/NfirstGroup/NgroupCount', $record);

            if ($fields['size'] === 0) {
                continue;
            }

            $entries[] = [
                'start' => $fields['start'],
                'end' => $fields['start'] + $fields['size'],
                'blockStart' => $fields['start'] - $fields['start'] % self::BLOCK_BYTES,
                'firstGroup' => $fields['firstGroup'],
                'groupCount' => $fields['groupCount'],
            ];
        }

        return $entries;
    }

    /** @return list<array{offset: int, storedBytes: int, isCompressed: bool, packedBytes: int}> */
    private static function groupEntries(string $table): array
    {
        $groups = [];

        foreach ($table === '' ? [] : mb_str_split($table, self::GROUP_BYTES, '8bit') as $record) {
            /** @var array{offset4: int, size: int, packedBytes: int} $fields */
            $fields = unpack('Noffset4/Nsize/NpackedBytes', $record);

            $groups[] = [
                'offset' => $fields['offset4'] * 4,
                'storedBytes' => $fields['size'] & ~self::COMPRESSED_FLAG,
                'isCompressed' => ($fields['size'] & self::COMPRESSED_FLAG) !== 0,
                'packedBytes' => $fields['packedBytes'],
            ];
        }

        return $groups;
    }

    /** @return array{start: int, end: int, blockStart: int, firstGroup: int, groupCount: int} */
    private function rawDataAt(int $offset): array
    {
        foreach ($this->rawData as $entry) {
            if ($offset >= $entry['start'] && $offset < $entry['end']) {
                return $entry;
            }
        }

        throw new RuntimeException('Byte ' . $offset . ' of the RVZ disc is not stored as raw data, like Wii partitions.');
    }

    /**
     * The bytes of a group, decompressed and unpacked. The last group read
     * is kept, as the disc is read in small pieces and in order.
     *
     * @param  int  $dataOffset  where the group starts inside its raw data, which places its padding
     */
    private function readGroup(int $group, int $dataOffset, int $bytes): string
    {
        if ($group === $this->cachedGroup) {
            return $this->cachedData;
        }

        $entry = $this->groups[$group] ?? throw new RuntimeException('Group ' . $group . ' is missing from the RVZ image.');

        if ($entry['storedBytes'] === 0) {
            $data = str_repeat("\0", $bytes);
        } else {
            $data = self::readAt($this->file, $this->fileSize, $entry['offset'], $entry['storedBytes']);
            $unpackedBytes = $entry['packedBytes'] === 0 ? $bytes : $entry['packedBytes'];

            if ($entry['isCompressed'] && $this->compression === self::ZSTANDARD) {
                $data = ZstdDecoder::decode($data, $unpackedBytes);
            }

            if ($entry['packedBytes'] !== 0) {
                $data = RvzPacking::unpack($data, $dataOffset, $bytes);
            }
        }

        if (mb_strlen($data, '8bit') !== $bytes) {
            throw new RuntimeException('Group ' . $group . ' of the RVZ image does not hold ' . $bytes . ' bytes.');
        }

        $this->cachedGroup = $group;
        $this->cachedData = $data;

        return $data;
    }
}
