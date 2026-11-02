<?php

declare(strict_types=1);

use Boquizo\RaHasher\Compression\ZstdDecoder;

/**
 * A zstd frame of tests/Fixtures/Zstd, made by libzstd: DolphinTool converted
 * a synthetic 4 MiB GameCube image (text, records, random nibbles, runs of
 * zeros and short repeats) to RVZ with zstd at the level of the name, and
 * each fixture is one group of the RVZ cut out at its offset and size. The
 * multi-block one comes from 2 MiB groups, the others from 128 KiB groups,
 * and the group table frame decodes to the RVZ's own group entries.
 */
function zstdFixture(string $name): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Zstd/' . $name . '.zst');
}

/**
 * A frame with the descriptor and fields of $header, a window descriptor by default.
 */
function zstdFrame(string $blocks, string $header = "\x00\x00"): string
{
    return "\x28\xB5\x2F\xFD" . $header . $blocks;
}

function zstdBlock(int $type, string $content, bool $last = true, ?int $size = null): string
{
    return mb_substr(pack('V', ($last ? 1 : 0) | ($type << 1) | (($size ?? mb_strlen($content, '8bit')) << 3)), 0, 3, '8bit') . $content;
}

/**
 * A backward bitstream whose fields, [value, bits], are read in the given order, ended by its marker bit.
 *
 * @param  list<array{int, int}>  $fields
 */
function zstdBackwardBits(array $fields): string
{
    $bits = '1' . implode('', array_map(fn (array $field): string => mb_str_pad(decbin($field[0]), $field[1], '0', STR_PAD_LEFT), $fields));
    $bits = mb_str_pad($bits, (int) ceil(mb_strlen($bits) / 8) * 8, '0', STR_PAD_LEFT);

    return strrev(implode('', array_map(fn (string $byte): string => chr((int) bindec($byte)), mb_str_split($bits, 8))));
}

/**
 * A forward bitstream of [value, bits] fields, least significant bit first.
 *
 * @param  list<array{int, int}>  $fields
 */
function zstdForwardBits(array $fields): string
{
    $bits = implode('', array_map(fn (array $field): string => strrev(mb_str_pad(decbin($field[0]), $field[1], '0', STR_PAD_LEFT)), $fields));

    return implode('', array_map(fn (string $byte): string => chr((int) bindec(strrev(mb_str_pad($byte, 8, '0')))), mb_str_split($bits, 8)));
}

/**
 * A Huffman literals section of one stream (type 2 with a table, 3 to reuse it) or four streams.
 */
function zstdHuffmanLiterals(int $type, int $size, string $payload, bool $fourStreams = false): string
{
    return mb_substr(pack('V', $type | ($fourStreams ? 4 : 0) | ($size << 4) | (mb_strlen($payload, '8bit') << 14)), 0, 3, '8bit') . $payload;
}

/** A Huffman table of direct weights where "a" is the code 0 and "b" the code 1. */
const ZSTD_HUFFMAN_AB = "\xE1" . "\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0" . "\x01";

it('decodes frames made by libzstd', function (string $fixture, int $size, string $md5): void {
    $decoded = ZstdDecoder::decode(zstdFixture($fixture), $size);

    expect(md5($decoded))->toBe($md5);
})->with([
    'disc header and text, level 5' => ['disc-header-text-l5', 131072, 'bee53eb86fdce7cd156ffb529e498a93'],
    'few literals in a single huffman stream, level 5' => ['few-literals-single-stream-l5', 131072, 'a9d583d6072f04f54782e7cf545e6fd0'],
    'random nibbles with direct huffman weights, level 5' => ['nibbles-direct-weights-l5', 131072, 'cf148dd1b5588491277c776fef6b3c23'],
    'short repeats copied over themselves, level 5' => ['overlapping-matches-l5', 131072, '212ee1ef9a7973c04bafdde7dd8e074f'],
    'blocks that repeat tables and offsets, level 19' => ['multi-block-repeat-tables-l19', 2097152, 'd8bd11b7b06dd18fd7cf3dbf5c52444e'],
    'rvz group table' => ['rvz-group-table-l5', 384, '0e0268b72359e34ffdfc3d63f7d63c45'],
]);

it('decodes hand-made frames', function (string $compressed, string $expected): void {
    expect(ZstdDecoder::decode($compressed))->toBe($expected);
})->with([
    'raw block' => [zstdFrame(zstdBlock(0, 'abc')), 'abc'],
    'rle block' => [zstdFrame(zstdBlock(1, 'z', size: 5)), 'zzzzz'],
    'single segment with a one byte content size' => [zstdFrame(zstdBlock(0, 'abc'), "\x20\x03"), 'abc'],
    'two byte content size' => [zstdFrame(zstdBlock(1, 'z', size: 300), "\x60\x2C\x00"), str_repeat('z', 300)],
    'four byte content size' => [zstdFrame(zstdBlock(0, 'abc'), "\xA0" . pack('V', 3)), 'abc'],
    'eight byte content size' => [zstdFrame(zstdBlock(0, 'abc'), "\xE0" . pack('P', 3)), 'abc'],
    'checksum' => [zstdFrame(zstdBlock(0, 'abc'), "\x24\x03") . strrev(mb_substr(hash('xxh64', 'abc', true), 4, 4, '8bit')), 'abc'],
    'dictionary id of zero' => [zstdFrame(zstdBlock(0, 'abc'), "\x01\x00\x00"), 'abc'],
    'two frames and a skippable one' => [zstdFrame(zstdBlock(0, 'ab')) . "\x5A\x2A\x4D\x18" . pack('V', 3) . 'xyz' . zstdFrame(zstdBlock(1, 'c', size: 2)), 'abcc'],
    'rle literals with one, two and three byte headers' => [
        zstdFrame(zstdBlock(2, "\x19q\x00", false) . zstdBlock(2, "\x45\x06r\x00", false) . zstdBlock(2, mb_substr(pack('V', 80013), 0, 3, '8bit') . "s\x00")),
        'qqq' . str_repeat('r', 100) . str_repeat('s', 5000),
    ],
    'huffman literals, then literals that reuse the table' => [
        zstdFrame(zstdBlock(2, zstdHuffmanLiterals(2, 4, ZSTD_HUFFMAN_AB . zstdBackwardBits([[0, 1], [1, 1], [1, 1], [0, 1]])) . "\x00", false)
            . zstdBlock(2, zstdHuffmanLiterals(3, 4, zstdBackwardBits([[1, 1], [0, 1], [0, 1], [1, 1]])) . "\x00")),
        'abbabaab',
    ],
    'rle sequence codes and a match over itself' => [zstdFrame(zstdBlock(2, "\x20abcd\x01\x54\x04\x02\x05" . zstdBackwardBits([[3, 2]]))), 'abcdabcdabcd'],
    'predefined sequence codes and a repeat offset without literals' => [
        zstdFrame(zstdBlock(0, 'wxyz', false) . zstdBlock(2, "\x00\x01\x00" . zstdBackwardBits([[0, 6], [0, 5], [0, 6]]))),
        'wxyzwxy',
    ],
]);

it('rejects malformed data', function (string $compressed, string $message): void {
    expect(fn (): string => ZstdDecoder::decode($compressed))->toThrow(RuntimeException::class, $message);
})->with([
    'no data' => ['', 'is truncated'],
    'half a frame' => [mb_substr(zstdFixture('few-literals-single-stream-l5'), 0, 150, '8bit'), 'is truncated'],
    'something else' => ["PK\x03\x04\x14\x00", 'is not a zstd frame'],
    'reserved header bit' => [zstdFrame(zstdBlock(0, 'abc'), "\x08\x00"), 'sets its reserved bit'],
    'dictionary id' => [zstdFrame(zstdBlock(0, 'abc'), "\x01\x00\x07"), 'needs a dictionary'],
    'reserved block type' => [zstdFrame(zstdBlock(3, '')), 'block of the reserved type'],
    'wrong checksum' => [zstdFrame(zstdBlock(0, 'abc'), "\x24\x03") . "\0\0\0\0", 'does not match its checksum'],
    'wrong content size' => [zstdFrame(zstdBlock(0, 'abc'), "\x20\x04"), 'does not decode to its content size'],
    'raw literals past the block' => [zstdFrame(zstdBlock(2, "\x28ab")), 'literals section does not fit its block'],
    'huffman literals past the block' => [zstdFrame(zstdBlock(2, mb_substr(pack('V', 2 | (4 << 4) | (50 << 14)), 0, 3, '8bit') . "\x00")), 'literals section does not fit its block'],
    'reused huffman table never sent' => [zstdFrame(zstdBlock(2, zstdHuffmanLiterals(3, 4, zstdBackwardBits([[1, 1], [0, 1], [0, 1], [1, 1]])) . "\x00")), 'Huffman table that their frame never sent'],
    'direct weights past the literals' => [zstdFrame(zstdBlock(2, zstdHuffmanLiterals(2, 4, "\xE1\x00") . "\x00")), 'does not fit its literals section'],
    'compressed weights past the literals' => [zstdFrame(zstdBlock(2, zstdHuffmanLiterals(2, 4, "\x7F\x00") . "\x00")), 'does not fit its literals section'],
    'weights that do not add up' => [zstdFrame(zstdBlock(2, zstdHuffmanLiterals(2, 4, "\x81\x31\x01") . "\x00")), 'Huffman table is corrupt'],
    'stream without marker bit' => [zstdFrame(zstdBlock(2, zstdHuffmanLiterals(2, 4, ZSTD_HUFFMAN_AB . "\x00") . "\x00")), 'does not end with its marker bit'],
    'huffman stream longer than its literals' => [
        zstdFrame(zstdBlock(2, zstdHuffmanLiterals(2, 4, ZSTD_HUFFMAN_AB . zstdBackwardBits([[0, 1], [1, 1], [1, 1], [0, 1], [0, 8]])) . "\x00")),
        'Huffman stream does not end with its literals',
    ],
    'four streams past the literals' => [zstdFrame(zstdBlock(2, zstdHuffmanLiterals(2, 8, ZSTD_HUFFMAN_AB . pack('v3', 100, 0, 0), true) . "\x00")), 'streams do not fit their section'],
    'too few literals for four streams' => [zstdFrame(zstdBlock(2, zstdHuffmanLiterals(2, 5, ZSTD_HUFFMAN_AB . pack('v3', 1, 1, 1) . "\x01\x01\x01\x01", true) . "\x00")), 'streams do not fit their section'],
    'bytes after no sequences' => [zstdFrame(zstdBlock(2, "\x00\x00\x00")), 'sequences section does not fit its block'],
    'reserved sequence modes' => [zstdFrame(zstdBlock(2, "\x00\x01\x01")), 'sets its reserved bits'],
    'reserved sequence modes after a three byte count' => [zstdFrame(zstdBlock(2, "\x00\xFF\x00\x00\x01")), 'sets its reserved bits'],
    'repeated sequence tables never sent' => [zstdFrame(zstdBlock(2, "\x00\x01\xFC")), 'reuse a table that their frame never sent'],
    'rle code out of range' => [zstdFrame(zstdBlock(2, "\x00\x01\x54\x28\x00\x00\x01")), 'repeat a code out of range'],
    'accuracy log too large' => [zstdFrame(zstdBlock(2, "\x00\x01\x80\x0F\x00\x00\x00\x01")), 'FSE table description is corrupt'],
    'too many zero probabilities' => [zstdFrame(zstdBlock(2, "\x00\x01\x20" . pack('V', 16 | (0x3FFFFF << 9)) . "\x01")), 'FSE table description is corrupt'],
    'too many symbols' => [
        zstdFrame(zstdBlock(2, "\x00\x01\x20" . zstdForwardBits([[0, 4], [1, 5], ...array_merge(...array_fill(0, 31, [[0, 2], [1, 5]]))]) . "\x01")),
        'FSE table description is corrupt',
    ],
    'more literals than the block holds' => [zstdFrame(zstdBlock(2, "\x20abcd\x01\x54\x0A\x02\x05" . zstdBackwardBits([[3, 2]]))), 'more literals than their block holds'],
    'offset past the output' => [zstdFrame(zstdBlock(2, "\x20abcd\x01\x54\x04\x04\x05" . zstdBackwardBits([[15, 4]]))), 'before the start of their frame'],
    'offset into the previous frame' => [
        zstdFrame(zstdBlock(0, 'abcd')) . zstdFrame(zstdBlock(2, "\x00\x01\x54\x00\x02\x05" . zstdBackwardBits([[3, 2]]))),
        'before the start of their frame',
    ],
    'sequences stream longer than its sequences' => [zstdFrame(zstdBlock(2, "\x20abcd\x01\x54\x04\x02\x05" . zstdBackwardBits([[3, 2], [0, 8]]))), 'sequences bitstream does not end with its last sequence'],
]);

it('rejects data that does not decode to the expected size', function (): void {
    expect(fn (): string => ZstdDecoder::decode(zstdFrame(zstdBlock(0, 'abc')), 4))
        ->toThrow(RuntimeException::class, 'does not decode to the expected size');
});
