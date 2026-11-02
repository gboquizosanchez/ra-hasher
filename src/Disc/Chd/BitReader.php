<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Disc\Chd;

/**
 * Reads values of 0 to 32 bits, most significant bit first, like libchdr's
 * bitstream. Bits past the end of the data read as zeros; overflowed()
 * tells afterwards whether any of them was consumed.
 */
final class BitReader
{
    private readonly int $length;

    private int $position = 0;

    public function __construct(private readonly string $data)
    {
        $this->length = mb_strlen($data, '8bit');
    }

    /**
     * The next bits, without consuming them.
     */
    public function peek(int $bits): int
    {
        $byteOffset = $this->position >> 3;
        $chunk = 0;

        for ($index = $byteOffset; $index < $byteOffset + 5; $index++) {
            $chunk = ($chunk << 8) | ord($this->data[$index] ?? "\0");
        }

        return ($chunk >> (40 - ($this->position & 7) - $bits)) & ((1 << $bits) - 1);
    }

    public function skip(int $bits): void
    {
        $this->position += $bits;
    }

    public function read(int $bits): int
    {
        $value = $this->peek($bits);
        $this->position += $bits;

        return $value;
    }

    /**
     * Whether more bits were consumed than the data has.
     */
    public function overflowed(): bool
    {
        return $this->position > $this->length * 8;
    }
}
