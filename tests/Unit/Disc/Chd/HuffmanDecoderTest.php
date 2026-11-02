<?php

declare(strict_types=1);

use Boquizo\RaHasher\Disc\Chd\BitReader;
use Boquizo\RaHasher\Disc\Chd\HuffmanDecoder;

/**
 * Decodes symbols until the reader has consumed all of the data.
 *
 * @param  list<array{int, int}>  $tree
 * @param  list<array{int, int}>  $codes
 * @return list<int>
 */
function huffmanSymbols(array $tree, array $codes, int $count): array
{
    $reader = new BitReader(chdBits([...$tree, ...$codes]));
    $decoder = HuffmanDecoder::importTreeRle($reader);

    return array_map(fn (): int => $decoder->decode($reader), range(1, $count));
}

it('decodes a tree whose codes are all 4 bits long', function (): void {
    $codes = array_map(fn (int $symbol): array => [$symbol, 4], range(15, 0, -1));

    expect(huffmanSymbols([[1, 4], [4, 4], [13, 4]], $codes, 16))->toBe(range(15, 0, -1));
});

it('gives the longest codes the lowest values', function (): void {
    $tree = [[1, 4], [1, 4], [2, 4], [3, 4], [3, 4], [1, 4], [0, 4], [9, 4]];
    $codes = [[0b1, 1], [0b01, 2], [0b000, 3], [0b001, 3], [0b1, 1]];

    expect(huffmanSymbols($tree, $codes, 5))->toBe([0, 1, 2, 3, 0]);
});

it('rejects invalid trees', function (array $tree, string $message): void {
    expect(fn (): HuffmanDecoder => HuffmanDecoder::importTreeRle(new BitReader(chdBits($tree))))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'more than 16 lengths' => [[[2, 4], [1, 4], [4, 4], [13, 4]], 'more than 16 code lengths'],
    'odd count of a length' => [[[2, 4], [1, 4], [0, 4], [12, 4]], 'not a canonical prefix code'],
    'three codes of 1 bit' => [[[1, 4], [1, 4], [1, 4], [1, 4], [1, 4], [1, 4], [1, 4], [0, 4], [10, 4]], 'not a canonical prefix code'],
    'codes longer than 8 bits' => [[[9, 4], [1, 4], [0, 4], [12, 4]], 'codes longer than 8 bits'],
]);

it('rejects bits that no code matches', function (): void {
    $tree = [[1, 4], [1, 4], [1, 4], [0, 4], [12, 4]];

    expect(huffmanSymbols($tree, [[0, 1]], 1))->toBe([0])
        ->and(fn (): array => huffmanSymbols($tree, [[0, 1], [1, 1]], 2))
        ->toThrow(RuntimeException::class, 'no Huffman code matches');
});
