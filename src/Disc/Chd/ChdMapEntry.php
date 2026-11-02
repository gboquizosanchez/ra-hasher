<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Disc\Chd;

/**
 * Where a hunk of a CHD v5 image lives. Types 0 to 3 are compressed with
 * the codec of that index of the header; their offset and length locate the
 * compressed data in the file. UNCOMPRESSED hunks take hunk-size bytes at
 * the offset, SELF hunks copy the hunk whose number is the offset, and ZERO
 * hunks are all zeros.
 */
final readonly class ChdMapEntry
{
    public const int UNCOMPRESSED = 4;

    public const int SELF = 5;

    /**
     * Not a type of the compressed map, whose 7 is a run-length code:
     * uncompressed maps mark zero-filled hunks with an offset of 0.
     */
    public const int ZERO = 7;

    /**
     * @param  int|null  $crc  the CRC-16 of the decompressed hunk, only in compressed maps and for hunks with data of their own
     */
    public function __construct(
        public int $type,
        public int $length,
        public int $offset,
        public ?int $crc,
    ) {}
}
