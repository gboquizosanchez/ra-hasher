<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Disc\Nintendo;

use HashContext;
use RuntimeException;

/**
 * Feeds a hash with what identifies a GameCube disc or a Wii partition, like
 * rcheevos' rc_hash_nintendo_disc_partition: the disc header, the apploader
 * (up to 1 MiB in all) and every segment of the boot DOL. Wii partitions
 * store their offsets divided by 4, hence the shift.
 *
 * rcheevos hashes whole buffers even when the file ends before them, with
 * whatever memory they held; a range past the end of the disc throws here
 * instead, so such a disc is left to RAHasher.
 */
final readonly class PartitionHash
{
    private const int BASE_HEADER_SIZE = 0x2440;

    private const int APPLOADER_HEADER_SIZE = 0x20;

    private const int APPLOADER_SIZES_OFFSET = self::BASE_HEADER_SIZE + 0x14;

    private const int MAX_HEADER_SIZE = 1024 * 1024;

    private const int DOL_OFFSET_FIELD = 0x420;

    private const int DOL_TABLE_SIZE = 0xD8;

    /** The 7 text and 11 data segments of a DOL: their offsets, load addresses (skipped) and sizes. */
    private const int DOL_SEGMENTS = 18;

    private const int MAX_CHUNK_SIZE = 1024 * 1024;

    /**
     * @param  int  $partitionOffset  where the partition starts on the disc, 0 for a GameCube disc
     * @param  int  $shift  the bits the offsets and sizes are shifted left by, 2 on Wii partitions
     *
     * @throws RuntimeException when the data lies past the end of the disc
     */
    public static function append(HashContext $context, NintendoDisc $disc, int $partitionOffset = 0, int $shift = 0): void
    {
        /** @var array{body: int, trailer: int} $apploader */
        $apploader = unpack('Nbody/Ntrailer', $disc->read($partitionOffset + self::APPLOADER_SIZES_OFFSET, 8));
        $headerSize = (self::BASE_HEADER_SIZE + self::APPLOADER_HEADER_SIZE + $apploader['body'] + $apploader['trailer']) & 0xFFFFFFFF;
        $headerSize = min($headerSize, self::MAX_HEADER_SIZE);

        if ($headerSize < self::DOL_OFFSET_FIELD + 4) {
            throw new RuntimeException('The apploader sizes overflow, rcheevos would read the DOL offset from outside the header.');
        }

        $header = $disc->read($partitionOffset, $headerSize);
        hash_update($context, $header);

        /** @var array{1: int} $dolOffset */
        $dolOffset = unpack('N', $header, self::DOL_OFFSET_FIELD);
        $dolPosition = $partitionOffset + ($dolOffset[1] << $shift);

        /** @var array<string, int> $table */
        $table = unpack('N' . self::DOL_SEGMENTS . 'offset/x' . self::DOL_SEGMENTS * 4 . '/N' . self::DOL_SEGMENTS . 'size', $disc->read($dolPosition, self::DOL_TABLE_SIZE));

        for ($segment = 1; $segment <= self::DOL_SEGMENTS; $segment++) {
            self::appendRange($context, $disc, $partitionOffset + ($table['offset' . $segment] << $shift), $table['size' . $segment] << $shift);
        }
    }

    /** Hashes the bytes of a segment 1 MiB at a time, as rcheevos reads them. */
    private static function appendRange(HashContext $context, NintendoDisc $disc, int $offset, int $size): void
    {
        for ($done = 0; $done < $size; $done += self::MAX_CHUNK_SIZE) {
            hash_update($context, $disc->read($offset + $done, min(self::MAX_CHUNK_SIZE, $size - $done)));
        }
    }
}
