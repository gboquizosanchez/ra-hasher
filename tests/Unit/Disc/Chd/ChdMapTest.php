<?php

declare(strict_types=1);

use Boquizo\RaHasher\Disc\Chd\ChdMap;
use Boquizo\RaHasher\Disc\Chd\ChdMapEntry;

/**
 * Every entry of a map, as [type, length, offset, crc].
 *
 * @return list<array{int, int, int, int|null}>
 */
function chdMapEntries(ChdMap $map): array
{
    return array_map(function (int $hunk) use ($map): array {
        $entry = $map->entry($hunk);

        return [$entry->type, $entry->length, $entry->offset, $entry->crc];
    }, range(0, $map->count() - 1));
}

/**
 * The map of a CHD v5 image of tests/Fixtures/Chd.
 */
function chdFixtureMap(string $name): ChdMap
{
    $image = (string) file_get_contents(dirname(__DIR__, 3) . '/Fixtures/Chd/' . $name . '.chd');

    /** @var array{compressor: int, logicalBytes: int, mapOffset: int, hunkBytes: int} $header */
    $header = unpack('x16/Ncompressor/x12/JlogicalBytes/JmapOffset/x8/NhunkBytes', $image);
    $hunkCount = (int) ceil($header['logicalBytes'] / $header['hunkBytes']);

    if ($header['compressor'] === 0) {
        return ChdMap::uncompressed(mb_substr($image, $header['mapOffset'], $hunkCount * 4, '8bit'), $hunkCount, $header['hunkBytes']);
    }

    /** @var array{1: int} $mapBytes */
    $mapBytes = unpack('N', $image, $header['mapOffset']);

    return ChdMap::compressed(mb_substr($image, $header['mapOffset'], 16 + $mapBytes[1], '8bit'), $hunkCount, $header['hunkBytes']);
}

it('reads hunks of every codec and uncompressed hunks one after another', function (): void {
    $records = [
        [0, 100, CHD_MAP_FIRST_OFFSET, 0xAAAA],
        [1, 200, CHD_MAP_FIRST_OFFSET + 100, 0xBBBB],
        [2, 300, CHD_MAP_FIRST_OFFSET + 300, 0xCCCC],
        [3, 400, CHD_MAP_FIRST_OFFSET + 600, 0xDDDD],
        [4, CHD_MAP_HUNK_BYTES, CHD_MAP_FIRST_OFFSET + 1000, 0xEEEE],
    ];
    $fields = [[100, 16], [0xAAAA, 16], [200, 16], [0xBBBB, 16], [300, 16], [0xCCCC, 16], [400, 16], [0xDDDD, 16], [0xEEEE, 16]];

    $map = ChdMap::compressed(compressedChdMap([0, 1, 2, 3, 4], $fields, $records), 5, CHD_MAP_HUNK_BYTES);

    expect($map->count())->toBe(5)
        ->and($map->entry(4))->toEqual(new ChdMapEntry(ChdMapEntry::UNCOMPRESSED, CHD_MAP_HUNK_BYTES, CHD_MAP_FIRST_OFFSET + 1000, 0xEEEE))
        ->and(chdMapEntries($map))->toBe($records);
});

it('reads hunks that copy earlier hunks', function (): void {
    $records = [
        [0, 10, CHD_MAP_FIRST_OFFSET, 1],
        [0, 20, CHD_MAP_FIRST_OFFSET + 10, 2],
        [ChdMapEntry::SELF, 0, 1, 0],
        [ChdMapEntry::SELF, 0, 1, 0],
        [ChdMapEntry::SELF, 0, 2, 0],
        [0, 30, CHD_MAP_FIRST_OFFSET + 30, 3],
    ];
    $fields = [[10, 16], [1, 16], [20, 16], [2, 16], [1, 8], [30, 16], [3, 16]];

    $map = ChdMap::compressed(compressedChdMap([0, 0, 5, 9, 10, 0], $fields, $records), 6, CHD_MAP_HUNK_BYTES);

    expect(chdMapEntries($map))->toBe([
        [0, 10, CHD_MAP_FIRST_OFFSET, 1],
        [0, 20, CHD_MAP_FIRST_OFFSET + 10, 2],
        [ChdMapEntry::SELF, 0, 1, null],
        [ChdMapEntry::SELF, 0, 1, null],
        [ChdMapEntry::SELF, 0, 2, null],
        [0, 30, CHD_MAP_FIRST_OFFSET + 30, 3],
    ]);
});

it('advances the offset by the whole length that was read', function (): void {
    $records = [
        [0, 0x10, CHD_MAP_FIRST_OFFSET, 1],
        [0, 0x20, CHD_MAP_FIRST_OFFSET + 0x1000010, 2],
    ];
    $fields = [[0x1000010, 32], [1, 16], [0x20, 32], [2, 16]];

    $map = ChdMap::compressed(compressedChdMap([0, 0], $fields, $records, lengthBits: 32), 2, CHD_MAP_HUNK_BYTES);

    expect(chdMapEntries($map))->toBe($records);
});

it('resolves chains of copies to the hunk that holds the data', function (): void {
    $hunkCount = 5000;
    $records = [[0, 10, CHD_MAP_FIRST_OFFSET, 1]];

    for ($hunk = 1; $hunk < $hunkCount; $hunk++) {
        $records[] = [ChdMapEntry::SELF, 0, $hunk - 1, 0];
    }

    $types = [0, ChdMapEntry::SELF, ...array_fill(0, $hunkCount - 2, 10)];
    $map = ChdMap::compressed(compressedChdMap($types, [[10, 16], [1, 16], [0, 8]], $records), $hunkCount, CHD_MAP_HUNK_BYTES);

    expect($map->resolve(0))->toBe(0)
        ->and($map->resolve($hunkCount - 1))->toBe(0)
        ->and($map->resolve($hunkCount - 1))->toBe(0)
        ->and($map->resolve(2500))->toBe(0);
});

it('repeats the last type for runs', function (array $symbols, int $type, int $hunkCount): void {
    $records = array_map(fn (int $hunk): array => [$type, CHD_MAP_HUNK_BYTES, CHD_MAP_FIRST_OFFSET + $hunk * CHD_MAP_HUNK_BYTES, $hunk], range(0, $hunkCount - 1));
    $fields = array_merge(...array_map(fn (int $hunk): array => $type === 0 ? [[CHD_MAP_HUNK_BYTES, 16], [$hunk, 16]] : [[$hunk, 16]], range(0, $hunkCount - 1)));

    $map = ChdMap::compressed(compressedChdMap($symbols, $fields, $records), $hunkCount, CHD_MAP_HUNK_BYTES);

    expect(chdMapEntries($map))->toBe($records);
})->with([
    'short run after a type' => [[4, 7, 2], 4, 6],
    'long run after a type' => [[0, 8, 1, 3], 0, 39],
    'run before any type' => [[7, 0], 0, 3],
]);

it('reads the maps of real images', function (string $name, int $hunk, array $entry): void {
    $map = chdFixtureMap($name);

    expect(array_slice(chdMapEntries($map), $hunk, 1))->toBe([$entry]);
})->with([
    'cdlz hunks' => ['mode1-cdlz', 1, [0, 1959, 229 + 1964, 8365]],
    'a run of cdlz hunks' => ['mode2', 11, [0, 664, 6721 + 678, 41880]],
    'a copied hunk' => ['audio-data-audio', 8, [ChdMapEntry::SELF, 0, 7, null]],
    'an uncompressed hunk' => ['raw-none', 5, [ChdMapEntry::UNCOMPRESSED, 4096, 5 * 4096, null]],
    'a zero hunk' => ['raw-none', 4, [ChdMapEntry::ZERO, 4096, 0, null]],
]);

it('rejects invalid compressed maps', function (string $map, int $hunkCount, string $message): void {
    expect(fn (): ChdMap => ChdMap::compressed($map, $hunkCount, CHD_MAP_HUNK_BYTES))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'no header' => [str_repeat("\0", 15), 1, 'has no header'],
    'shorter than its header says' => [mb_substr(compressedChdMap([4], [[0, 16]]), 0, 17, '8bit'), 1, 'shorter than its header says'],
    'lengths of 33 bits' => [compressedChdMap([4], [[0, 16]], lengthBits: 33), 1, 'longer than 32 bits'],
    'selves of 33 bits' => [compressedChdMap([4], [[0, 16]], selfBits: 33), 1, 'longer than 32 bits'],
    'parents of 33 bits' => [compressedChdMap([4], [[0, 16]], parentBits: 33), 1, 'longer than 32 bits'],
    'a run past the last hunk' => [compressedChdMap([7, 0], []), 2, 'repeats a type past its last hunk'],
    'an unknown type' => [compressedChdMap([14], []), 1, 'unknown hunk type 14'],
    'a parent hunk' => [compressedChdMap([6], []), 1, 'needs a parent image'],
    'a parent hunk at the same place' => [compressedChdMap([11], []), 1, 'needs a parent image'],
    'the last parent hunk' => [compressedChdMap([12], []), 1, 'needs a parent image'],
    'the parent hunk after the last' => [compressedChdMap([13], []), 1, 'needs a parent image'],
    'a hunk that copies itself' => [compressedChdMap([5], [[0, 8]]), 1, 'copies hunk 0, which does not come before it'],
    'a hunk that copies the next one' => [compressedChdMap([0, 10], [[10, 16], [1, 16]]), 2, 'copies hunk 1, which does not come before it'],
    'fields past the end' => [compressedChdMap([0], []), 1, 'ends before its last hunk'],
    'fields past the end before the last hunk' => [compressedChdMap([0, 0], []), 2, 'ends before its last hunk'],
    'a wrong crc' => [compressedChdMap([4], [[0xEEEE, 16]], [[4, CHD_MAP_HUNK_BYTES, CHD_MAP_FIRST_OFFSET, 0xEEEE]], crc: 0x1234), 1, 'does not match its CRC'],
]);

it('rejects uncompressed maps shorter than their hunks', function (): void {
    expect(fn (): ChdMap => ChdMap::uncompressed(str_repeat("\0", 7), 2, CHD_MAP_HUNK_BYTES))
        ->toThrow(RuntimeException::class, 'shorter than its 2 hunks');
});

it('has no entries outside its hunks', function (int $hunk): void {
    $map = ChdMap::uncompressed(pack('N', 1), 1, CHD_MAP_HUNK_BYTES);

    expect(fn (): ChdMapEntry => $map->entry($hunk))->toThrow(RuntimeException::class, 'has no hunk ' . $hunk);
})->with([-1, 1]);

it('stops reading types where the bitstream ends instead of filling every hunk', function (): void {
    memory_reset_peak_usage();
    $baseline = memory_get_peak_usage();

    expect(fn (): ChdMap => ChdMap::compressed(compressedChdMap([4], []), 50_000_000, CHD_MAP_HUNK_BYTES))
        ->toThrow(RuntimeException::class, 'ends before its last hunk')
        ->and(memory_get_peak_usage() - $baseline)->toBeLessThan(10 * 1024 * 1024);
});
