<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Disc\Rvz;

/**
 * The pseudorandom padding of GameCube and Wii discs, a lagged Fibonacci
 * generator with f = xor, j = 32 and k = 521, as described in Dolphin's
 * docs/WiaAndRvz.md and DiscIO/LaggedFibonacciGenerator.cpp. PHP integers
 * are 64-bit, so the left shifts are masked back to 32 bits.
 */
final readonly class LaggedFibonacciGenerator
{
    public const int SEED_BYTES = self::SEED_WORDS * 4;

    private const int SEED_WORDS = 17;

    private const int K = 521;

    private const int J = 32;

    /** The bytes output per state of the buffer. */
    private const int BUFFER_BYTES = self::K * 4;

    /** The state is advanced this many times before the first output. */
    private const int WARM_UP_ROUNDS = 4;

    /**
     * The bytes the generator outputs after skipping the first ones.
     *
     * @param  string  $seed  the 17 big endian words of the seed
     */
    public static function generate(string $seed, int $skip, int $length): string
    {
        /** @var list<int> $buffer */
        $buffer = array_values((array) unpack('N' . self::SEED_WORDS, $seed));

        for ($index = self::SEED_WORDS; $index < self::K; $index++) {
            $buffer[$index] = (($buffer[$index - 17] << 23) & 0xFFFFFFFF) ^ ($buffer[$index - 16] >> 9) ^ $buffer[$index - 1];
        }

        for ($round = 0; $round < self::WARM_UP_ROUNDS + intdiv($skip, self::BUFFER_BYTES); $round++) {
            $buffer = self::advance($buffer);
        }

        $position = $skip % self::BUFFER_BYTES;
        $output = '';

        while (true) {
            $output .= mb_substr(self::output($buffer), $position, $length - mb_strlen($output, '8bit'), '8bit');

            if (mb_strlen($output, '8bit') >= $length) {
                return $output;
            }

            $position = 0;
            $buffer = self::advance($buffer);
        }
    }

    /**
     * Advances the state by a whole buffer length.
     *
     * @param  list<int>  $buffer
     * @return list<int>
     */
    private static function advance(array $buffer): array
    {
        for ($index = 0; $index < self::J; $index++) {
            $buffer[$index] ^= $buffer[$index + self::K - self::J];
        }

        for ($index = self::J; $index < self::K; $index++) {
            $buffer[$index] ^= $buffer[$index - self::J];
        }

        return $buffer;
    }

    /**
     * The bytes of every word: bits 24-31, 18-25 (not 16-23), 8-15 and 0-7.
     *
     * @param  list<int>  $buffer
     */
    private static function output(array $buffer): string
    {
        return pack('N*', ...array_map(static fn (int $word): int => ($word & 0xFF00FFFF) | (($word >> 2) & 0x00FF0000), $buffer));
    }
}
