<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Compression;

use RuntimeException;

/**
 * Decodes the FLAC frames of the "cdfl" hunks of CHD images: 16-bit stereo
 * frames with no "fLaC" marker or metadata before them, as libchdr's flac
 * decoder supplies the STREAMINFO itself (44100 Hz, 2 channels, 16 bits,
 * the codec's block size). Follows the frame format of RFC 9639 and, like
 * libchdr's dr_flac, checks the CRC-8 of every header and the CRC-16 of
 * every frame.
 *
 * The samples come out interleaved, left then right, as 16-bit big-endian
 * values: libchdr swaps dr_flac's native samples on little-endian hosts.
 * Bits are read through an integer cache from an array of bytes, written
 * out in the residual loop, because string functions per bit or method
 * calls per sample make decoding many times slower.
 */
final class FlacDecoder
{
    private const int SYNC_CODE = 0x3FFE;

    private const int BITS_PER_SAMPLE = 16;

    private const int INDEPENDENT_STEREO = 1;

    private const int LEFT_SIDE = 8;

    private const int RIGHT_SIDE = 9;

    private const int MID_SIDE = 10;

    private const int CONSTANT = 0;

    private const int VERBATIM = 1;

    /** The coefficients of the FIXED predictors of orders 0 to 4. */
    private const array FIXED_COEFFICIENTS = [[], [1], [2, -1], [3, -3, 1], [4, -6, 4, -1]];

    /** @var list<int>|null the leading zeros of every byte from 1 to 255 */
    private static ?array $leadingZeros = null;

    /** @var array<int, list<int>> by CRC width */
    private static array $crcTables = [];

    private readonly int $byteCount;

    private int $bytePosition = 0;

    /** Bits read from the bytes but not consumed yet, in the low $cacheBits bits. */
    private int $cache = 0;

    private int $cacheBits = 0;

    /**
     * @param  list<int>  $bytes
     */
    private function __construct(private readonly array $bytes)
    {
        $this->byteCount = count($bytes);
    }

    /**
     * @param  int  $sampleFrames  the stereo samples the stream must hold
     * @param  int  $blockSize  the block size of the stream, which no frame may exceed
     * @return array{samples: string, consumedBytes: int} the samples and the bytes of the frames that hold them
     *
     * @throws RuntimeException when the stream is corrupt, not 16-bit stereo or does not hold exactly $sampleFrames samples
     */
    public static function decodeStereo16(string $data, int $sampleFrames, int $blockSize): array
    {
        /** @var list<int> $bytes */
        $bytes = array_values((array) unpack('C*', $data));
        $decoder = new self($bytes);
        $samples = [];
        $decodedSamples = 0;

        while ($decodedSamples < $sampleFrames) {
            $decodedSamples += $decoder->decodeFrame($samples, $blockSize);
        }

        if ($decodedSamples !== $sampleFrames) {
            throw new RuntimeException('The FLAC stream holds more samples than expected.');
        }

        return ['samples' => pack('n*', ...$samples), 'consumedBytes' => $decoder->bitPosition() >> 3];
    }

    /**
     * The CRC of the bytes, most significant bit first and starting at 0,
     * as FLAC computes both of its CRCs.
     *
     * @param  list<int>  $bytes
     */
    private static function crc(array $bytes, int $width, int $polynomial): int
    {
        $table = self::$crcTables[$width] ??= self::crcTable($width, $polynomial);
        $mask = (1 << $width) - 1;
        $crc = 0;

        foreach ($bytes as $byte) {
            $crc = (($crc << 8) & $mask) ^ $table[(($crc >> ($width - 8)) ^ $byte) & 0xFF];
        }

        return $crc;
    }

    /**
     * @return list<int>
     */
    private static function crcTable(int $width, int $polynomial): array
    {
        $topBit = 1 << ($width - 1);
        $mask = (1 << $width) - 1;
        $table = [];

        for ($byte = 0; $byte < 256; $byte++) {
            $crc = $byte << ($width - 8);

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & $topBit) !== 0 ? (($crc << 1) ^ $polynomial) & $mask : ($crc << 1) & $mask;
            }

            $table[] = $crc;
        }

        return $table;
    }

    /**
     * The leading zeros of every byte, 8 for zero.
     *
     * @return list<int>
     */
    private static function leadingZeros(): array
    {
        if (self::$leadingZeros === null) {
            self::$leadingZeros = [8];

            for ($byte = 1; $byte < 256; $byte++) {
                $zeros = 0;

                while ((($byte << $zeros) & 0x80) === 0) {
                    $zeros++;
                }

                self::$leadingZeros[] = $zeros;
            }
        }

        return self::$leadingZeros;
    }

    /**
     * Appends the interleaved samples of the next frame.
     *
     * @param  list<int>  $samples
     * @return int the samples of each channel
     */
    private function decodeFrame(array &$samples, int $maxBlockSize): int
    {
        $frameStart = $this->bitPosition() >> 3;

        if ($frameStart >= $this->byteCount) {
            throw new RuntimeException('The FLAC stream ends before its samples do.');
        }

        if ($this->read(14) !== self::SYNC_CODE) {
            throw new RuntimeException('The FLAC frame does not start with a sync code.');
        }

        $reservedBit = $this->read(1);
        $this->read(1);
        $blockSizeCode = $this->read(4);
        $sampleRateCode = $this->read(4);
        $channelAssignment = $this->read(4);
        $sampleSizeCode = $this->read(3);

        if ($reservedBit !== 0 || $this->read(1) !== 0) {
            throw new RuntimeException('The FLAC frame header sets a reserved bit.');
        }

        if ($channelAssignment !== self::INDEPENDENT_STEREO && ($channelAssignment < self::LEFT_SIDE || $channelAssignment > self::MID_SIDE)) {
            throw new RuntimeException('The FLAC frame does not hold 2 channels.');
        }

        if ($sampleSizeCode !== 0 && $sampleSizeCode !== 4) {
            throw new RuntimeException('The FLAC frame does not hold 16-bit samples.');
        }

        $this->skipCodedNumber();

        $blockSize = match (true) {
            $blockSizeCode === 0 => throw new RuntimeException('The FLAC frame uses the reserved block size code.'),
            $blockSizeCode === 1 => 192,
            $blockSizeCode <= 5 => 576 << ($blockSizeCode - 2),
            $blockSizeCode === 6 => $this->read(8) + 1,
            $blockSizeCode === 7 => $this->read(16) + 1,
            default => 256 << ($blockSizeCode - 8),
        };

        $this->read(match (true) {
            $sampleRateCode === 15 => throw new RuntimeException('The FLAC frame uses the invalid sample rate code.'),
            $sampleRateCode === 12 => 8,
            $sampleRateCode >= 13 => 16,
            default => 0,
        });

        $headerEnd = $this->bitPosition() >> 3;

        if ($this->read(8) !== self::crc($this->bytesBetween($frameStart, $headerEnd), 8, 0x07)) {
            throw new RuntimeException('The FLAC frame header does not match its CRC-8.');
        }

        if ($blockSize > $maxBlockSize) {
            throw new RuntimeException('The FLAC frame holds more samples than the block size of the stream.');
        }

        $first = $this->decodeSubframe($blockSize, self::BITS_PER_SAMPLE + ($channelAssignment === self::RIGHT_SIDE ? 1 : 0));
        $second = $this->decodeSubframe($blockSize, self::BITS_PER_SAMPLE + ($channelAssignment === self::LEFT_SIDE || $channelAssignment === self::MID_SIDE ? 1 : 0));

        $this->cacheBits -= $this->cacheBits & 7;
        $frameEnd = $this->bitPosition() >> 3;
        $crc16 = $this->read(16);

        if ($this->bitPosition() > $this->byteCount * 8) {
            throw new RuntimeException('The FLAC stream ends before its samples do.');
        }

        if ($crc16 !== self::crc($this->bytesBetween($frameStart, $frameEnd), 16, 0x8005)) {
            throw new RuntimeException('The FLAC frame does not match its CRC-16.');
        }

        for ($index = 0; $index < $blockSize; $index++) {
            $left = $first[$index];
            $right = $second[$index];

            if ($channelAssignment === self::LEFT_SIDE) {
                $right = $left - $right;
            } elseif ($channelAssignment === self::RIGHT_SIDE) {
                $left += $right;
            } elseif ($channelAssignment === self::MID_SIDE) {
                $mid = ($left << 1) | ($right & 1);
                $left = ($mid + $right) >> 1;
                $right = ($mid - $right) >> 1;
            }

            $samples[] = $left;
            $samples[] = $right;
        }

        return $blockSize;
    }

    /**
     * Skips the UTF-8-like coded frame or sample number of a frame header.
     */
    private function skipCodedNumber(): void
    {
        $first = $this->read(8);
        $continuationBytes = $first < 0x80 ? 0 : self::leadingZeros()[(~$first) & 0xFF] - 1;

        if (($continuationBytes === 0 && $first >= 0x80) || $first === 0xFF) {
            throw new RuntimeException('The FLAC frame number is not validly coded.');
        }

        for ($index = 0; $index < $continuationBytes; $index++) {
            if (($this->read(8) & 0xC0) !== 0x80) {
                throw new RuntimeException('The FLAC frame number is not validly coded.');
            }
        }
    }

    /**
     * @return list<int>
     */
    private function decodeSubframe(int $blockSize, int $bitsPerSample): array
    {
        if ($this->read(1) !== 0) {
            throw new RuntimeException('The FLAC subframe sets its padding bit.');
        }

        $type = $this->read(6);
        $wastedBits = 0;

        if ($this->read(1) === 1) {
            do {
                $wastedBits++;

                if ($wastedBits >= $bitsPerSample) {
                    throw new RuntimeException('The FLAC subframe wastes all the bits of its samples.');
                }
            } while ($this->read(1) === 0);
        }

        $bitsPerSample -= $wastedBits;

        if ($type === self::CONSTANT) {
            $samples = array_fill(0, $blockSize, $this->readSigned($bitsPerSample));
        } elseif ($type === self::VERBATIM) {
            $samples = $this->readSignedValues($blockSize, $bitsPerSample);
        } elseif ($type >= 0x08 && $type <= 0x0C) {
            $samples = $this->decodePredicted($blockSize, $bitsPerSample, self::FIXED_COEFFICIENTS[$type - 0x08], 0);
        } elseif ($type >= 0x20) {
            $order = $type - 0x1F;
            $warmUp = $this->readSignedValues($order, $bitsPerSample);
            $precision = $this->read(4) + 1;
            $shift = $this->readSigned(5);

            if ($precision === 16 || $shift < 0) {
                throw new RuntimeException('The FLAC subframe has an invalid predictor precision or shift.');
            }

            $samples = $this->decodePredicted($blockSize, $bitsPerSample, $this->readSignedValues($order, $precision), $shift, $warmUp);
        } else {
            throw new RuntimeException('The FLAC subframe has the reserved type ' . $type . '.');
        }

        if ($wastedBits > 0) {
            foreach ($samples as $index => $sample) {
                $samples[$index] = $sample << $wastedBits;
            }
        }

        return $samples;
    }

    /**
     * The samples of a FIXED or LPC subframe: the warm-up samples, then each
     * sample predicted from the previous ones plus its residual.
     *
     * @param  list<int>  $coefficients  from the latest previous sample back
     * @param  list<int>|null  $warmUp  read here when null, as for FIXED subframes
     * @return list<int>
     */
    private function decodePredicted(int $blockSize, int $bitsPerSample, array $coefficients, int $shift, ?array $warmUp = null): array
    {
        $order = count($coefficients);
        $samples = $warmUp ?? $this->readSignedValues($order, $bitsPerSample);
        $residuals = $this->decodeResidual($blockSize, $order);

        for ($index = $order; $index < $blockSize; $index++) {
            $prediction = 0;

            foreach ($coefficients as $back => $coefficient) {
                $prediction += $coefficient * $samples[$index - 1 - $back];
            }

            $samples[] = $residuals[$index - $order] + ($prediction >> $shift);
        }

        return $samples;
    }

    /**
     * The Rice-coded residuals of a predicted subframe.
     *
     * @return list<int>
     */
    private function decodeResidual(int $blockSize, int $order): array
    {
        $codingMethod = $this->read(2);

        if ($codingMethod > 1) {
            throw new RuntimeException('The FLAC residual uses the reserved coding method ' . $codingMethod . '.');
        }

        $parameterBits = 4 + $codingMethod;
        $escapeParameter = (1 << $parameterBits) - 1;
        $partitionOrder = $this->read(4);
        $partitionSamples = $blockSize >> $partitionOrder;

        if ($partitionSamples << $partitionOrder !== $blockSize || $partitionSamples < $order) {
            throw new RuntimeException('The FLAC residual partitions do not fit the block.');
        }

        $leadingZeros = self::leadingZeros();
        $bytes = $this->bytes;
        $residuals = [];

        for ($partition = 0; $partition < 1 << $partitionOrder; $partition++) {
            $count = $partition === 0 ? $partitionSamples - $order : $partitionSamples;
            $parameter = $this->read($parameterBits);

            if ($parameter === $escapeParameter) {
                $rawBits = $this->read(5);

                array_push($residuals, ...$this->readSignedValues($count, $rawBits));

                continue;
            }

            $mask = (1 << $parameter) - 1;
            $cache = $this->cache;
            $cacheBits = $this->cacheBits;
            $bytePosition = $this->bytePosition;

            for ($index = 0; $index < $count; $index++) {
                $quotient = 0;

                while (true) {
                    if ($cacheBits < 8) {
                        $cache = ($cache << 8) | ($bytes[$bytePosition++] ?? 0);
                        $cacheBits += 8;
                    }

                    $top = ($cache >> ($cacheBits - 8)) & 0xFF;

                    if ($top !== 0) {
                        break;
                    }

                    $quotient += 8;
                    $cacheBits -= 8;

                    if ($bytePosition > $this->byteCount) {
                        throw new RuntimeException('The FLAC stream ends before its samples do.');
                    }
                }

                $cacheBits -= $leadingZeros[$top] + 1;

                while ($cacheBits < $parameter) {
                    $cache = ($cache << 8) | ($bytes[$bytePosition++] ?? 0);
                    $cacheBits += 8;
                }

                $cacheBits -= $parameter;
                // The zig-zag folded value: (quotient + leading zeros of the top byte) << parameter | the parameter bits.
                $folded = (($quotient + $leadingZeros[$top]) << $parameter) | (($cache >> $cacheBits) & $mask);
                $residuals[] = ($folded >> 1) ^ -($folded & 1);
            }

            $this->cache = $cache;
            $this->cacheBits = $cacheBits;
            $this->bytePosition = $bytePosition;
        }

        return $residuals;
    }

    /**
     * The next bits, consumed.
     *
     * @phpstan-impure
     */
    private function read(int $bitCount): int
    {
        while ($this->cacheBits < $bitCount) {
            $this->cache = ($this->cache << 8) | ($this->bytes[$this->bytePosition++] ?? 0);
            $this->cacheBits += 8;
        }

        $this->cacheBits -= $bitCount;

        return ($this->cache >> $this->cacheBits) & ((1 << $bitCount) - 1);
    }

    private function readSigned(int $bitCount): int
    {
        $value = $this->read($bitCount);

        return $bitCount === 0 ? 0 : $value - (($value >> ($bitCount - 1)) << $bitCount);
    }

    /**
     * @return list<int>
     */
    private function readSignedValues(int $count, int $bitCount): array
    {
        $values = [];

        for ($index = 0; $index < $count; $index++) {
            $values[] = $this->readSigned($bitCount);
        }

        return $values;
    }

    private function bitPosition(): int
    {
        return $this->bytePosition * 8 - $this->cacheBits;
    }

    /**
     * @return list<int>
     */
    private function bytesBetween(int $start, int $end): array
    {
        return array_slice($this->bytes, $start, $end - $start);
    }
}
