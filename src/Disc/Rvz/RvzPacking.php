<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Disc\Rvz;

use RuntimeException;

/**
 * Decodes the RVZ packing of a group: runs of stored bytes and runs of
 * padding regenerated from a seed (Dolphin's RVZPackDecompressor). Each run
 * starts with a big endian size whose top bit marks padding, which is then
 * followed by the 68 bytes of its seed.
 */
final readonly class RvzPacking
{
    private const int PADDING_FLAG = 0x80000000;

    /** The padding of a disc restarts every 32 KiB block. */
    private const int BLOCK_BYTES = 0x8000;

    /**
     * @param  int  $dataOffset  where the group starts on the disc, which places the padding inside its block
     * @param  int  $size  the bytes the group holds once unpacked
     *
     * @throws RuntimeException when the packed data is cut short or does not unpack to $size bytes
     */
    public static function unpack(string $packed, int $dataOffset, int $size): string
    {
        $packedLength = mb_strlen($packed, '8bit');
        $position = 0;
        $output = '';

        while ($position < $packedLength) {
            if ($position + 4 > $packedLength) {
                throw new RuntimeException('The RVZ packed data ends inside a run size.');
            }

            /** @var array{1: int} $header */
            $header = unpack('N', $packed, $position);
            $runBytes = $header[1] & ~self::PADDING_FLAG;
            $position += 4;
            $written = mb_strlen($output, '8bit');

            if ($runBytes > $size - $written) {
                throw new RuntimeException('The RVZ packed data unpacks past the end of its group.');
            }

            $isPadding = ($header[1] & self::PADDING_FLAG) !== 0;
            $storedBytes = $isPadding ? LaggedFibonacciGenerator::SEED_BYTES : $runBytes;

            if ($position + $storedBytes > $packedLength) {
                throw new RuntimeException('The RVZ packed data ends inside a run.');
            }

            $stored = mb_substr($packed, $position, $storedBytes, '8bit');
            $position += $storedBytes;

            $output .= $isPadding
                ? LaggedFibonacciGenerator::generate($stored, ($dataOffset + $written) % self::BLOCK_BYTES, $runBytes)
                : $stored;
        }

        if (mb_strlen($output, '8bit') !== $size) {
            throw new RuntimeException('The RVZ packed data does not fill its group.');
        }

        return $output;
    }
}
