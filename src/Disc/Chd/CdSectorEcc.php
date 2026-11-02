<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Disc\Chd;

/**
 * Rebuilds the sync pattern and the P and Q parity (the Reed-Solomon ECC)
 * of a raw 2352-byte CD sector, like libchdr's ecc_generate. The CD codecs
 * of CHD v5 drop them from data sectors and flag the sectors to rebuild.
 */
final class CdSectorEcc
{
    public const int SECTOR_BYTES = 2352;

    private const string SYNC = "\x00\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\x00";

    /** Where the data the parity covers starts, right after the sync. */
    private const int DATA_OFFSET = 12;

    /** The mode byte of the sector header, relative to the data. */
    private const int MODE = 3;

    /** The bytes of the sector header, which count as zeros in mode 2. */
    private const int HEADER_BYTES = 4;

    private const int P_OFFSET = 0x81C;

    private const int P_ROWS = 86;

    private const int P_COLUMNS = 24;

    private const int Q_ROWS = 52;

    private const int Q_COLUMNS = 43;

    /** Q rows run diagonally: each pair of rows starts 86 bytes after the last, each column 88 bytes after the last. */
    private const int Q_ROW_STEP = 86;

    private const int Q_COLUMN_STEP = 88;

    /** The data, header and P parity the Q parity covers. */
    private const int Q_SPAN = 2236;

    /** @var array<int, int> multiplication by 2 in GF(2^8) */
    private static array $low = [];

    /** @var array<int, int> the inverse of x XOR 2x in GF(2^8) */
    private static array $high = [];

    /** @var list<list<int>> the data offsets of every P row */
    private static array $pOffsets = [];

    /** @var list<list<int>> the data offsets of every Q row */
    private static array $qOffsets = [];

    public static function regenerate(string $sector): string
    {
        self::buildTables();

        /** @var array<int, int> $bytes */
        $bytes = unpack('C*', mb_substr($sector, self::DATA_OFFSET, self::Q_SPAN, '8bit'));
        $data = array_values($bytes);

        if ($data[self::MODE] === 2) {
            array_splice($data, 0, self::HEADER_BYTES, array_fill(0, self::HEADER_BYTES, 0));
        }

        $pParity = self::parity($data, self::$pOffsets);
        array_splice($data, self::P_OFFSET - self::DATA_OFFSET, count($pParity), $pParity);
        $qParity = self::parity($data, self::$qOffsets);

        return self::SYNC
            . mb_substr($sector, self::DATA_OFFSET, self::P_OFFSET - self::DATA_OFFSET, '8bit')
            . pack('C*', ...$pParity)
            . pack('C*', ...$qParity);
    }

    /**
     * The two parity bytes of every row: all the first bytes, then all the
     * second ones.
     *
     * @param  array<int, int>  $data
     * @param  list<list<int>>  $offsets
     * @return list<int>
     */
    private static function parity(array $data, array $offsets): array
    {
        $low = self::$low;
        $high = self::$high;
        $firstBytes = [];
        $secondBytes = [];

        foreach ($offsets as $rowOffsets) {
            $first = 0;
            $second = 0;

            foreach ($rowOffsets as $offset) {
                $byte = $data[$offset];
                $first = $low[$first ^ $byte];
                $second ^= $byte;
            }

            $first = $high[$low[$first] ^ $second];
            $firstBytes[] = $first;
            $secondBytes[] = $first ^ $second;
        }

        return [...$firstBytes, ...$secondBytes];
    }

    private static function buildTables(): void
    {
        if (self::$low !== []) {
            return;
        }

        for ($value = 0; $value < 256; $value++) {
            $doubled = (($value << 1) ^ (($value & 0x80) !== 0 ? 0x11D : 0)) & 0xFF;
            self::$low[$value] = $doubled;
            self::$high[$value ^ $doubled] = $value;
        }

        for ($row = 0; $row < self::P_ROWS; $row++) {
            self::$pOffsets[] = array_map(fn (int $column): int => $row + self::P_ROWS * $column, range(0, self::P_COLUMNS - 1));
        }

        for ($row = 0; $row < self::Q_ROWS; $row++) {
            self::$qOffsets[] = array_map(fn (int $column): int => (self::Q_ROW_STEP * ($row >> 1) + ($row & 1) + self::Q_COLUMN_STEP * $column) % self::Q_SPAN, range(0, self::Q_COLUMNS - 1));
        }
    }
}
