<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Disc\Wbfs;

use Boquizo\RaHasher\Disc\Nintendo\NintendoDisc;
use Override;
use RuntimeException;
use SplFileInfo;
use SplFileObject;

/**
 * A Wii disc stored in a WBFS file, read like Dolphin's WbfsFileReader
 * (Source/Core/DiscIO/WbfsBlob.cpp). The file may be split in up to ten
 * parts, the .wbfs one followed by .wbf1, .wbf2… (the last character of the
 * extension replaced by the part number), read as a single stream.
 *
 * The disc is cut in WBFS sectors (2 MiB usually) and a table after the
 * header tells which sector of the file holds each one. The sectors are
 * stored as on the disc, still encrypted. A sector the table maps to 0 was
 * not stored: Dolphin then reads the first sector of the file (the header)
 * and so does this reader, so the hashes match the ISO image DolphinTool
 * converts it to. Like Dolphin, the disc is always as big as a dual layer one.
 */
final readonly class WbfsFile implements NintendoDisc
{
    private const string MAGIC = 'WBFS';

    /** The bytes of a dual layer Wii disc, 143432 * 2 sectors of 0x8000 bytes. */
    private const int DISC_SIZE = 143432 * 2 * 0x8000;

    private const int DISC_HEADER_COPY_SIZE = 0x100;

    private const int HEADER_SIZE = 13;

    private const int MIN_WBFS_SECTOR_SHIFT = 15;

    /** Wider shifts than Dolphin would accept, but they keep every size inside 64 bits. */
    private const int MAX_HD_SECTOR_SHIFT = 31;

    private const int MAX_WBFS_SECTOR_SHIFT = 40;

    private const int MAX_PARTS = 10;

    /**
     * @param  list<array{file: SplFileObject, base: int, size: int}>  $parts
     * @param  list<int>  $sectorMap  the sector of the file holding each sector of the disc
     */
    private function __construct(
        private array $parts,
        private int $sectorShift,
        private array $sectorMap,
    ) {}

    /**
     * @throws RuntimeException when the file is not a WBFS image with a disc,
     *                          its parts do not add up to its size or it is truncated
     */
    public static function open(SplFileInfo $file): self
    {
        $parts = self::openParts($file->getPathname());
        $header = (string) $parts[0]['file']->fread(self::HEADER_SIZE);

        if (mb_strlen($header, '8bit') < self::HEADER_SIZE) {
            throw new RuntimeException('The WBFS header is truncated.');
        }

        /** @var array{magic: string, hdSectors: int, hdShift: int, wbfsShift: int, disc: int} $fields */
        $fields = unpack('a4magic/NhdSectors/ChdShift/CwbfsShift/x2/Cdisc', $header);

        if ($fields['magic'] !== self::MAGIC) {
            throw new RuntimeException('The file is not a WBFS image.');
        }

        if ($fields['hdShift'] > self::MAX_HD_SECTOR_SHIFT || $fields['wbfsShift'] < self::MIN_WBFS_SECTOR_SHIFT || $fields['wbfsShift'] > self::MAX_WBFS_SECTOR_SHIFT) {
            throw new RuntimeException('The WBFS sector sizes are out of range.');
        }

        $last = $parts[array_key_last($parts)];

        if ($last['base'] + $last['size'] !== $fields['hdSectors'] << $fields['hdShift']) {
            throw new RuntimeException('The WBFS parts do not add up to the size in its header.');
        }

        if ($fields['disc'] === 0) {
            throw new RuntimeException('The WBFS image holds no disc.');
        }

        $sectorSize = 1 << $fields['wbfsShift'];
        $sectors = intdiv(self::DISC_SIZE + $sectorSize - 1, $sectorSize);
        $parts[0]['file']->fseek((1 << $fields['hdShift']) + self::DISC_HEADER_COPY_SIZE);
        $table = (string) $parts[0]['file']->fread($sectors * 2);

        if (mb_strlen($table, '8bit') < $sectors * 2) {
            throw new RuntimeException('The WBFS sector table is truncated.');
        }

        /** @var array<int, int> $sectorMap */
        $sectorMap = unpack('n*', $table);

        return new self($parts, $fields['wbfsShift'], array_values($sectorMap));
    }

    #[Override]
    public function read(int $offset, int $length): string
    {
        if ($offset < 0 || $length < 0 || $length > self::DISC_SIZE - $offset) {
            throw new RuntimeException('The read lies outside the Wii disc.');
        }

        $bytes = '';
        $sectorSize = 1 << $this->sectorShift;

        while ($length > 0) {
            $inSector = $offset & ($sectorSize - 1);
            $address = $this->sectorMap[$offset >> $this->sectorShift] * $sectorSize + $inSector;
            $part = $this->partAt($address);
            $chunk = min($length, $part['base'] + $part['size'] - $address, $sectorSize - $inSector);

            $part['file']->fseek($address - $part['base']);
            $bytes .= (string) $part['file']->fread($chunk);
            $offset += $chunk;
            $length -= $chunk;
        }

        return $bytes;
    }

    #[Override]
    public function size(): int
    {
        return self::DISC_SIZE;
    }

    /**
     * The .wbfs file and the parts that follow it, each one with the address
     * it starts at in the joined stream.
     *
     * @return non-empty-list<array{file: SplFileObject, base: int, size: int}>
     */
    private static function openParts(string $path): array
    {
        $parts = [];
        $base = 0;

        for ($part = 0; $part < self::MAX_PARTS; $part++) {
            $partPath = $part === 0 ? $path : mb_substr($path, 0, -1, '8bit') . $part;

            if ($part > 0 && ! is_file($partPath)) {
                break;
            }

            $file = new SplFileObject($partPath, 'rb');

            /** @var array{size: int} $stat */
            $stat = $file->fstat();
            $parts[] = ['file' => $file, 'base' => $base, 'size' => $stat['size']];
            $base += $stat['size'];
        }

        return $parts;
    }

    /**
     * @return array{file: SplFileObject, base: int, size: int}
     */
    private function partAt(int $address): array
    {
        foreach ($this->parts as $part) {
            if ($address < $part['base'] + $part['size']) {
                return $part;
            }
        }

        throw new RuntimeException('The WBFS sector table points past the end of the file.');
    }
}
