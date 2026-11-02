<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Disc\Chd;

use RuntimeException;

/**
 * Decodes the 16 symbols of a CHD v5 compressed hunk map, Huffman coded
 * with codes of at most 8 bits (libchdr's huffman decoder with 16 codes
 * and 8 maximum bits). The tree comes run-length encoded at the start of
 * the bitstream and the codes are canonical. Unlike libchdr, an incomplete
 * tree does not decode the bits that no code covers.
 */
final class HuffmanDecoder
{
    private const int CODES = 16;

    private const int MAX_BITS = 8;

    /** The bits of each run-length encoded tree entry, libchdr's choice for 8 maximum bits. */
    private const int TREE_ENTRY_BITS = 4;

    /** A tree entry with this value introduces a run or a literal length of 1. */
    private const int TREE_ESCAPE = 1;

    /** The shortest run of lengths, added to the encoded count. */
    private const int TREE_MINIMUM_RUN = 3;

    private const int LENGTH_MASK = 0xF;

    private const int SYMBOL_SHIFT = 4;

    /**
     * @param  array<int, int>  $lookup  for every value of the next 8 bits, the symbol shifted left by 4 and the length of its code, or 0 when no code starts with those bits
     */
    private function __construct(private readonly array $lookup) {}

    /**
     * Reads a run-length encoded tree and builds its canonical codes.
     */
    public static function importTreeRle(BitReader $reader): self
    {
        $lengths = [];

        while (count($lengths) < self::CODES) {
            $length = $reader->read(self::TREE_ENTRY_BITS);

            if ($length !== self::TREE_ESCAPE) {
                $lengths[] = $length;

                continue;
            }

            $length = $reader->read(self::TREE_ENTRY_BITS);

            if ($length === self::TREE_ESCAPE) {
                $lengths[] = $length;

                continue;
            }

            $repeat = $reader->read(self::TREE_ENTRY_BITS) + self::TREE_MINIMUM_RUN;

            if (count($lengths) + $repeat > self::CODES) {
                throw new RuntimeException('The Huffman tree of the CHD map has more than ' . self::CODES . ' code lengths.');
            }

            array_push($lengths, ...array_fill(0, $repeat, $length));
        }

        return new self(self::lookupTable($lengths, self::canonicalCodes($lengths)));
    }

    public function decode(BitReader $reader): int
    {
        $entry = $this->lookup[$reader->peek(self::MAX_BITS)];

        if ($entry === 0) {
            throw new RuntimeException('The CHD map has bits that no Huffman code matches.');
        }

        $reader->skip($entry & self::LENGTH_MASK);

        return $entry >> self::SYMBOL_SHIFT;
    }

    /**
     * Assigns the codes like libchdr's assign_canonical_codes: the longest
     * codes take the lowest values. Every length but 1 must fill whole
     * pairs of codes, and at most 2 codes fit in a length of 1.
     *
     * @param  list<int>  $lengths
     * @return list<int>
     */
    private static function canonicalCodes(array $lengths): array
    {
        $lengthCounts = array_fill(0, self::MAX_BITS + 1, 0);

        foreach ($lengths as $length) {
            if ($length > self::MAX_BITS) {
                throw new RuntimeException('The Huffman tree of the CHD map has codes longer than ' . self::MAX_BITS . ' bits.');
            }

            $lengthCounts[$length]++;
        }

        $nextCodes = $lengthCounts;
        $start = 0;

        for ($length = self::MAX_BITS; $length > 0; $length--) {
            $total = $start + $lengthCounts[$length];

            if ($length === 1 ? $total > 2 : $total % 2 !== 0) {
                throw new RuntimeException('The Huffman tree of the CHD map is not a canonical prefix code.');
            }

            $nextCodes[$length] = $start;
            $start = $total >> 1;
        }

        $codes = [];

        foreach ($lengths as $length) {
            $codes[] = $length > 0 ? $nextCodes[$length]++ : 0;
        }

        return $codes;
    }

    /**
     * @param  list<int>  $lengths
     * @param  list<int>  $codes
     * @return array<int, int>
     */
    private static function lookupTable(array $lengths, array $codes): array
    {
        $lookup = array_fill(0, 1 << self::MAX_BITS, 0);

        foreach ($lengths as $symbol => $length) {
            if ($length === 0) {
                continue;
            }

            $shift = self::MAX_BITS - $length;
            $first = $codes[$symbol] << $shift;

            for ($value = $first; $value < $first + (1 << $shift); $value++) {
                $lookup[$value] = ($symbol << self::SYMBOL_SHIFT) | $length;
            }
        }

        return $lookup;
    }
}
