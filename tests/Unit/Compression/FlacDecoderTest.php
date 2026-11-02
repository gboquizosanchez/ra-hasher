<?php

declare(strict_types=1);

use Boquizo\RaHasher\Compression\FlacDecoder;

/**
 * A CRC of FLAC frames: most significant bit first, starting at 0.
 */
function flacCrc(string $bytes, int $width, int $polynomial): int
{
    $topBit = 1 << ($width - 1);
    $mask = (1 << $width) - 1;
    $crc = 0;

    foreach (unpack('C*', $bytes) ?: [] as $byte) {
        $crc ^= (int) $byte << ($width - 8);

        for ($bit = 0; $bit < 8; $bit++) {
            $crc = ($crc & $topBit) !== 0 ? (($crc << 1) ^ $polynomial) & $mask : ($crc << 1) & $mask;
        }
    }

    return $crc;
}

/**
 * The fields of a frame header up to its CRC-8, with the block size and
 * sample rate at its end when their codes ask for them.
 *
 * @return list<array{int, int}>
 */
function flacHeader(int $blockSize = 4, int $channelAssignment = 1, int $blockSizeCode = 6, int $sampleRateCode = 9, int $sampleSizeCode = 4, string $codedNumber = "\0", int $blockingStrategy = 0, int $firstReservedBit = 0, int $secondReservedBit = 0): array
{
    return [
        [0x3FFE, 14], [$firstReservedBit, 1], [$blockingStrategy, 1], [$blockSizeCode, 4], [$sampleRateCode, 4],
        [$channelAssignment, 4], [$sampleSizeCode, 3], [$secondReservedBit, 1],
        ...array_map(fn (int $byte): array => [$byte, 8], array_values(unpack('C*', $codedNumber) ?: [])),
        ...match ($blockSizeCode) {
            6 => [[$blockSize - 1, 8]],
            7 => [[$blockSize - 1, 16]],
            default => [],
        },
        ...match ($sampleRateCode) {
            12 => [[44, 8]],
            13, 14 => [[4410, 16]],
            default => [],
        },
    ];
}

/**
 * Two's complement fields of the given bits.
 *
 * @param  list<int>  $values
 * @return list<array{int, int}>
 */
function flacSigned(array $values, int $bits): array
{
    return array_map(fn (int $value): array => [$value & ((1 << $bits) - 1), $bits], $values);
}

/**
 * @return list<array{int, int}>
 */
function flacSubframeHeader(int $type, int $wastedBits = 0, int $paddingBit = 0): array
{
    return [[$paddingBit, 1], [$type, 6], ...($wastedBits === 0 ? [[0, 1]] : [[1, 1], [1, $wastedBits]])];
}

/**
 * @return list<array{int, int}>
 */
function constantSubframe(int $value, int $bits = 16): array
{
    return [...flacSubframeHeader(0), ...flacSigned([$value], $bits)];
}

/**
 * @param  list<int>  $samples  without their wasted bits
 * @return list<array{int, int}>
 */
function verbatimSubframe(array $samples, int $bits = 16, int $wastedBits = 0): array
{
    return [...flacSubframeHeader(1, $wastedBits), ...flacSigned($samples, $bits - $wastedBits)];
}

/**
 * @param  list<int>  $warmUp  as many samples as the order
 * @param  list<array{int, int}>  $residual
 * @return list<array{int, int}>
 */
function fixedSubframe(array $warmUp, array $residual): array
{
    return [...flacSubframeHeader(8 + count($warmUp)), ...flacSigned($warmUp, 16), ...$residual];
}

/**
 * @param  list<int>  $warmUp  as many samples as the order
 * @param  list<int>  $coefficients  from the latest previous sample back
 * @param  list<array{int, int}>  $residual
 * @return list<array{int, int}>
 */
function lpcSubframe(array $warmUp, int $precision, int $shift, array $coefficients, array $residual): array
{
    return [
        ...flacSubframeHeader(0x1F + count($warmUp)), ...flacSigned($warmUp, 16),
        [$precision - 1, 4], ...flacSigned([$shift], 5), ...flacSigned($coefficients, $precision), ...$residual,
    ];
}

/**
 * A residual in as many partitions as given, a power of 2.
 *
 * @param  list<array{0: int, 1: list<int>, 2?: int}>  $partitions  the Rice parameter and values of each partition, plus their bits after the escape parameter
 * @return list<array{int, int}>
 */
function flacResidual(array $partitions, int $codingMethod = 0): array
{
    $parameterBits = 4 + $codingMethod;
    $fields = [[$codingMethod, 2], [(int) round(log(count($partitions), 2)), 4]];

    foreach ($partitions as $partition) {
        [$parameter, $values] = $partition;
        $fields[] = [$parameter, $parameterBits];

        if ($parameter === (1 << $parameterBits) - 1) {
            array_push($fields, [$partition[2] ?? 0, 5], ...flacSigned($values, $partition[2] ?? 0));

            continue;
        }

        foreach ($values as $value) {
            $folded = $value < 0 ? -2 * $value - 1 : 2 * $value;
            array_push($fields, [1, ($folded >> $parameter) + 1], [$folded & ((1 << $parameter) - 1), $parameter]);
        }
    }

    return $fields;
}

/**
 * A frame with valid CRCs.
 *
 * @param  list<array{int, int}>  $header
 * @param  list<array{int, int}>  ...$subframes
 */
function flacFrame(array $header, array ...$subframes): string
{
    $headerBytes = chdBits($header);
    $frame = $headerBytes . chr(flacCrc($headerBytes, 8, 0x07)) . chdBits(array_merge(...$subframes));

    return $frame . pack('n', flacCrc($frame, 16, 0x8005));
}

function flacConstantFrame(int $blockSize = 4, int $blockSizeCode = 6, int $sampleRateCode = 9, int $sampleSizeCode = 4, string $codedNumber = "\0", int $blockingStrategy = 0): string
{
    return flacFrame(flacHeader($blockSize, 1, $blockSizeCode, $sampleRateCode, $sampleSizeCode, $codedNumber, $blockingStrategy), constantSubframe(7), constantSubframe(-7));
}

/**
 * Interleaved 16-bit big-endian samples.
 *
 * @param  list<int>  $left
 * @param  list<int>  $right
 */
function bigEndianStereo(array $left, array $right): string
{
    return pack('n*', ...array_merge(...array_map(null, $left, $right)));
}

function withFlippedByte(string $bytes, int $offset): string
{
    return mb_substr($bytes, 0, $offset, '8bit') . chr(ord($bytes[$offset]) ^ 1) . mb_substr($bytes, $offset + 1, null, '8bit');
}

it('decodes every type of subframe', function (array $subframe, array $samples): void {
    $frame = flacFrame(flacHeader(count($samples)), $subframe, constantSubframe(0));

    expect(FlacDecoder::decodeStereo16($frame, count($samples), count($samples)))
        ->toBe(['samples' => bigEndianStereo($samples, array_fill(0, count($samples), 0)), 'consumedBytes' => mb_strlen($frame, '8bit')]);
})->with([
    'constant' => [constantSubframe(-5), [-5, -5, -5, -5]],
    'verbatim' => [verbatimSubframe([1, -2, 32767, -32768]), [1, -2, 32767, -32768]],
    'verbatim with wasted bits' => [verbatimSubframe([1, -1, 3, 0], wastedBits: 2), [4, -4, 12, 0]],
    'fixed order 0' => [fixedSubframe([], flacResidual([[2, [3, -1, 0, 2]]])), [3, -1, 0, 2]],
    'fixed order 1' => [fixedSubframe([10], flacResidual([[1, [1, 1, -2]]])), [10, 11, 12, 10]],
    'fixed order 2' => [fixedSubframe([1, 2], flacResidual([[0, [0, 1]]])), [1, 2, 3, 5]],
    'fixed order 3' => [fixedSubframe([1, 2, 4], flacResidual([[0, [1]]])), [1, 2, 4, 8]],
    'fixed order 4' => [fixedSubframe([1, 2, 4, 8], flacResidual([[0, [0]]])), [1, 2, 4, 8, 15]],
    'lpc' => [lpcSubframe([100, 50], 4, 1, [3, -1], flacResidual([[1, [2, -1]]])), [100, 50, 27, 14]],
    'lpc with a negative prediction' => [lpcSubframe([-100], 3, 2, [3], flacResidual([[3, [-1, 4, 0]]])), [-100, -76, -53, -40]],
]);

it('decodes every kind of residual partition', function (array $residual, array $samples): void {
    $frame = flacFrame(flacHeader(count($samples)), fixedSubframe([], $residual), constantSubframe(0));

    expect(FlacDecoder::decodeStereo16($frame, count($samples), count($samples))['samples'])
        ->toBe(bigEndianStereo($samples, array_fill(0, count($samples), 0)));
})->with([
    'long unary quotients' => [flacResidual([[0, [20, -31, 0, 1]]]), [20, -31, 0, 1]],
    '5-bit rice parameters' => [flacResidual([[16, [-30000, 30000, 1, 0]]], 1), [-30000, 30000, 1, 0]],
    'escaped 4-bit parameter' => [flacResidual([[15, [-64, 63, 0, -1], 7]]), [-64, 63, 0, -1]],
    'escaped 5-bit parameter' => [flacResidual([[31, [-256, 255, 3, -3], 9]], 1), [-256, 255, 3, -3]],
    'escaped with no bits' => [flacResidual([[15, [0, 0, 0, 0], 0]]), [0, 0, 0, 0]],
    'several partitions' => [flacResidual([[1, [1, 2]], [15, [-3, 4], 4], [0, [0, -1]], [3, [9, -9]]]), [1, 2, -3, 4, 0, -1, 9, -9]],
]);

it('reads the first partition without the warm-up samples', function (): void {
    $frame = flacFrame(flacHeader(), fixedSubframe([5], flacResidual([[1, [1]], [0, [2, -3]]])), constantSubframe(0));

    expect(FlacDecoder::decodeStereo16($frame, 4, 4)['samples'])->toBe(bigEndianStereo([5, 6, 8, 5], [0, 0, 0, 0]));
});

it('undoes the stereo decorrelation', function (int $channelAssignment, array $first, array $second, array $left, array $right): void {
    $frame = flacFrame(
        flacHeader(3, $channelAssignment),
        verbatimSubframe($first, $channelAssignment === 9 ? 17 : 16),
        verbatimSubframe($second, $channelAssignment === 8 || $channelAssignment === 10 ? 17 : 16),
    );

    expect(FlacDecoder::decodeStereo16($frame, 3, 3)['samples'])->toBe(bigEndianStereo($left, $right));
})->with([
    'independent channels' => [1, [10, 5, -3], [6, 2, 3], [10, 5, -3], [6, 2, 3]],
    'left and side' => [8, [10, 5, -3], [4, 3, -6], [10, 5, -3], [6, 2, 3]],
    'side and right' => [9, [4, 3, -6], [6, 2, 3], [10, 5, -3], [6, 2, 3]],
    'mid and side' => [10, [8, 3, 0], [4, 3, -6], [10, 5, -3], [6, 2, 3]],
    'side of 17 bits' => [8, [32767, -32768, 0], [65535, -65535, 0], [32767, -32768, 0], [-32768, 32767, 0]],
]);

it('reads every block size, sample rate and frame number coding', function (string $frame, int $blockSize): void {
    expect(FlacDecoder::decodeStereo16($frame, $blockSize, $blockSize))
        ->toBe(['samples' => bigEndianStereo(array_fill(0, $blockSize, 7), array_fill(0, $blockSize, -7)), 'consumedBytes' => mb_strlen($frame, '8bit')]);
})->with([
    'block size 192' => [flacConstantFrame(192, 1), 192],
    'block size 576 times a power of 2' => [flacConstantFrame(1152, 3), 1152],
    'block size 256 times a power of 2' => [flacConstantFrame(512, 9), 512],
    '8-bit block size after the header' => [flacConstantFrame(4, 6), 4],
    '16-bit block size after the header' => [flacConstantFrame(300, 7), 300],
    'sample rate of the stream' => [flacConstantFrame(sampleRateCode: 0), 4],
    'sample rate in kHz after the header' => [flacConstantFrame(sampleRateCode: 12), 4],
    'sample rate in Hz after the header' => [flacConstantFrame(sampleRateCode: 13), 4],
    'sample rate in tens of Hz after the header' => [flacConstantFrame(sampleRateCode: 14), 4],
    'sample size of the stream' => [flacConstantFrame(sampleSizeCode: 0), 4],
    'frame number of 2 bytes' => [flacConstantFrame(codedNumber: "\xC2\x80"), 4],
    'sample number of 7 bytes' => [flacConstantFrame(codedNumber: "\xFE\x80\x80\x80\x80\x80\x80", blockingStrategy: 1), 4],
]);

it('decodes consecutive frames and tells where the last one ends', function (): void {
    $frames = flacConstantFrame() . flacFrame(flacHeader(2), constantSubframe(-1), constantSubframe(1));

    expect(FlacDecoder::decodeStereo16($frames . 'subcode', 6, 4))
        ->toBe(['samples' => bigEndianStereo([7, 7, 7, 7, -1, -1], [-7, -7, -7, -7, 1, 1]), 'consumedBytes' => mb_strlen($frames, '8bit')]);
});

it('rejects malformed frames', function (string $frame, string $message): void {
    expect(fn (): array => FlacDecoder::decodeStereo16($frame, 4, 4))->toThrow(RuntimeException::class, $message);
})->with([
    'no data' => ['', 'ends before its samples do'],
    'no sync code' => ["\xFF\xF0" . mb_substr(flacConstantFrame(), 2, null, '8bit'), 'does not start with a sync code'],
    'reserved bit after the sync code' => [flacFrame(flacHeader(firstReservedBit: 1)), 'sets a reserved bit'],
    'reserved bit after the sample size' => [flacFrame(flacHeader(secondReservedBit: 1)), 'sets a reserved bit'],
    'one channel' => [flacFrame(flacHeader(channelAssignment: 0)), 'does not hold 2 channels'],
    'three channels' => [flacFrame(flacHeader(channelAssignment: 2)), 'does not hold 2 channels'],
    'reserved channel assignment' => [flacFrame(flacHeader(channelAssignment: 11)), 'does not hold 2 channels'],
    '8-bit samples' => [flacFrame(flacHeader(sampleSizeCode: 1)), 'does not hold 16-bit samples'],
    'reserved sample size' => [flacFrame(flacHeader(sampleSizeCode: 3)), 'does not hold 16-bit samples'],
    'frame number starting with a continuation byte' => [flacFrame(flacHeader(codedNumber: "\x80")), 'frame number is not validly coded'],
    'frame number starting with 0xFF' => [flacFrame(flacHeader(codedNumber: "\xFF")), 'frame number is not validly coded'],
    'frame number with a bad continuation byte' => [flacFrame(flacHeader(codedNumber: "\xC2\x00")), 'frame number is not validly coded'],
    'reserved block size code' => [flacFrame(flacHeader(blockSizeCode: 0)), 'reserved block size code'],
    'invalid sample rate code' => [flacFrame(flacHeader(sampleRateCode: 15)), 'invalid sample rate code'],
    'wrong crc-8' => [withFlippedByte(flacConstantFrame(), 6), 'does not match its CRC-8'],
    'wrong crc-16' => [withFlippedByte(flacConstantFrame(), 14), 'does not match its CRC-16'],
    'subframe padding bit' => [flacFrame(flacHeader(), [...flacSubframeHeader(0, paddingBit: 1), [0, 16]]), 'sets its padding bit'],
    'reserved subframe type' => [flacFrame(flacHeader(), flacSubframeHeader(2)), 'reserved type 2'],
    'fixed order 5' => [flacFrame(flacHeader(), flacSubframeHeader(0x0D)), 'reserved type 13'],
    'reserved type before lpc' => [flacFrame(flacHeader(), flacSubframeHeader(0x1F)), 'reserved type 31'],
    'all bits wasted' => [flacFrame(flacHeader(), verbatimSubframe([0, 0, 0, 0], wastedBits: 16)), 'wastes all the bits'],
    'lpc precision of 16 bits' => [flacFrame(flacHeader(), lpcSubframe([1], 16, 0, [1], [])), 'invalid predictor precision or shift'],
    'negative lpc shift' => [flacFrame(flacHeader(), lpcSubframe([1], 4, -1, [1], [])), 'invalid predictor precision or shift'],
    'reserved residual coding method' => [flacFrame(flacHeader(), fixedSubframe([], [[2, 2]])), 'reserved coding method 2'],
    'partitions shorter than the warm-up' => [flacFrame(flacHeader(), fixedSubframe([1, 2, 3], flacResidual([[0, [0]], [0, [0, 0]]]))), 'partitions do not fit the block'],
    'partitions that do not divide the block' => [flacFrame(flacHeader(3), fixedSubframe([], flacResidual([[0, [0]], [0, [0, 0]]]))), 'partitions do not fit the block'],
    'truncated residual' => [mb_substr(flacFrame(flacHeader(), fixedSubframe([], flacResidual([[0, [100, 0, 0, 0]]]))), 0, 9, '8bit'), 'ends before its samples do'],
    'truncated crc-16' => [mb_substr(flacConstantFrame(), 0, -1, '8bit'), 'ends before its samples do'],
]);

it('rejects frames of more samples than the stream allows', function (int $sampleFrames, int $blockSize, string $message): void {
    expect(fn (): array => FlacDecoder::decodeStereo16(flacConstantFrame(), $sampleFrames, $blockSize))->toThrow(RuntimeException::class, $message);
})->with([
    'more than expected' => [3, 4, 'holds more samples than expected'],
    'more than the block size' => [4, 3, 'more samples than the block size of the stream'],
    'fewer than expected' => [8, 4, 'ends before its samples do'],
]);
