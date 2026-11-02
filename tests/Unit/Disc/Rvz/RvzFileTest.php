<?php

declare(strict_types=1);

use Boquizo\RaHasher\Disc\Rvz\RvzFile;

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rvz-' . uniqid();
    mkdir($this->directory);
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: []);
    rmdir($this->directory);
});

/**
 * An RVZ image laid out like Dolphin writes it: the headers, the raw data
 * table, the group table and the data of every group, without compression
 * unless the method says otherwise.
 *
 * @param  list<array{int, int, int, int}>  $rawData  the disc offset, size, first group and group count of every entry
 * @param  list<string>  $groups  the stored data of every group
 */
function syntheticRvz(array $rawData, array $groups, int $discSize, int $chunkBytes = 0x8000, int $compression = 0): string
{
    $rawTable = implode('', array_map(static fn (array $entry): string => pack('JJNN', ...$entry), $rawData));
    $groupTableOffset = 0x124 + mb_strlen($rawTable, '8bit');
    $dataOffset = $groupTableOffset + count($groups) * 12;
    $groupTable = '';
    $groupData = '';

    foreach ($groups as $stored) {
        $groupTable .= pack('NNN', intdiv($dataOffset + mb_strlen($groupData, '8bit'), 4), mb_strlen($stored, '8bit'), 0);
        $groupData .= mb_str_pad($stored, intdiv(mb_strlen($stored, '8bit') + 3, 4) * 4, "\0", STR_PAD_RIGHT, '8bit');
    }

    $head = "RVZ\x01" . str_repeat("\0", 0x20) . pack('J', $discSize) . str_repeat("\0", 0x20)
        . pack('NNN', $compression, 0, $chunkBytes) . str_repeat('H', 0x80) . str_repeat("\0", 36)
        . pack('NJNNJN', count($rawData), 0x124, mb_strlen($rawTable, '8bit'), count($groups), $groupTableOffset, mb_strlen($groupTable, '8bit'))
        . str_repeat("\0", 8);

    return $head . $rawTable . $groupTable . $groupData;
}

/**
 * A disc of 0x20000 bytes whose raw data covers its first and third 32 KiB
 * blocks, with an empty entry between them.
 */
function gappedRvz(): string
{
    return syntheticRvz(
        [[0x80, 0x7F80, 0, 1], [0x9000, 0, 1, 0], [0x10000, 0x8000, 1, 1]],
        [str_repeat('A', 0x8000), str_repeat('B', 0x8000)],
        0x20000,
    );
}

function rvzOnDisk(string $directory, string $image): RvzFile
{
    $path = $directory . DIRECTORY_SEPARATOR . 'image.rvz';
    file_put_contents($path, $image);

    return RvzFile::open(new SplFileInfo($path));
}

it('reads the whole disc dolphin converted', function (string $fixture): void {
    $rvz = RvzFile::open(new SplFileInfo(rvzFixturePath($fixture)));
    $iso = syntheticGameCubeIso();

    expect($rvz->size())->toBe(GAME_CUBE_ISO_BYTES)
        ->and($rvz->read(0, GAME_CUBE_ISO_BYTES))->toBe($iso);
})->with(['synthetic-zstd', 'synthetic-none']);

it('reads ranges that span the disc header and the groups', function (int $offset, int $length): void {
    $rvz = RvzFile::open(new SplFileInfo(rvzFixturePath('synthetic-zstd')));

    expect($rvz->read($offset, $length))->toBe(mb_substr(syntheticGameCubeIso(), $offset, $length, '8bit'));
})->with([
    'inside the disc header' => [0x10, 0x20],
    'across the disc header' => [0x70, 0x20],
    'across groups' => [0x1FF00, 0x200],
    'padding mid group' => [0x67F00, 0x1000],
    'up to the end' => [0x87FF0, 0x10],
    'nothing at the end' => [GAME_CUBE_ISO_BYTES, 0],
]);

it('reads the raw data entries that cover the disc', function (): void {
    $rvz = rvzOnDisk($this->directory, gappedRvz());

    expect($rvz->read(0x7E, 4))->toBe('HHAA')
        ->and($rvz->read(0x10000, 4))->toBe('BBBB');
});

it('throws on what no raw data covers', function (int $offset): void {
    $rvz = rvzOnDisk($this->directory, gappedRvz());

    expect(fn (): string => $rvz->read($offset, 4))->toThrow(RuntimeException::class, 'is not stored as raw data');
})->with([
    'between entries' => [0x9000],
    'after the last entry' => [0x18000],
]);

it('throws on ranges outside the disc', function (int $offset, int $length): void {
    $rvz = rvzOnDisk($this->directory, gappedRvz());

    expect(fn (): string => $rvz->read($offset, $length))->toThrow(RuntimeException::class, 'The RVZ disc ends before the data it points to.');
})->with([
    'negative offset' => [-1, 4],
    'negative length' => [0, -1],
    'offset past the end' => [0x20001, 0],
    'range past the end' => [0x1FFFF, 2],
]);

it('throws on groups the image does not hold', function (array $rawData, array $groups, string $message): void {
    $rvz = rvzOnDisk($this->directory, syntheticRvz($rawData, $groups, 0x10000));

    expect(fn (): string => $rvz->read(0x8000, 4))->toThrow(RuntimeException::class, $message);
})->with([
    'fewer groups than the raw data covers' => [[[0x80, 0xFF80, 0, 1]], [str_repeat('A', 0x8000)], 'The RVZ raw data has fewer groups than it covers.'],
    'group missing from the table' => [[[0x80, 0xFF80, 0, 2]], [str_repeat('A', 0x8000)], 'Group 1 is missing from the RVZ image.'],
    'short group' => [[[0x80, 0xFF80, 0, 2]], [str_repeat('A', 0x8000), 'short'], 'Group 1 of the RVZ image does not hold 32768 bytes.'],
]);

it('throws on group data past the end of the file', function (): void {
    $image = syntheticRvz([[0x80, 0x7F80, 0, 1]], [str_repeat('A', 0x8000)], 0x8000);
    $rvz = rvzOnDisk($this->directory, mb_substr($image, 0, -4, '8bit'));

    expect(fn (): string => $rvz->read(0x80, 4))->toThrow(RuntimeException::class, 'The RVZ image ends before its data does.');
});

it('opens images with chunks of any multiple of 2 mib', function (): void {
    expect(rvzOnDisk($this->directory, syntheticRvz([], [], 0x600000, 0x600000))->size())->toBe(0x600000);
});

it('rejects files that are not rvz images it can read', function (string $image, string $message): void {
    expect(fn (): RvzFile => rvzOnDisk($this->directory, $image))->toThrow(RuntimeException::class, $message);
})->with([
    'other magic' => [substr_replace(syntheticRvz([], [], 0x8000), 'WIA', 0, 3), 'The file is not an RVZ disc image.'],
    'cut header' => [mb_substr(syntheticRvz([], [], 0x8000), 0, 0x100, '8bit'), 'The file is not an RVZ disc image.'],
    'lzma compression' => [syntheticRvz([], [], 0x8000, compression: 3), 'RVZ images compressed with method 3 are not supported.'],
    'chunks under 32 kib' => [syntheticRvz([], [], 0x8000, 0x4000), 'The RVZ chunks of 16384 bytes are not valid.'],
    'chunks of no valid size' => [syntheticRvz([], [], 0x8000, 0x30000), 'The RVZ chunks of 196608 bytes are not valid.'],
    'short table' => [substr_replace(syntheticRvz([[0x80, 0x7F80, 0, 1]], [], 0x8000), pack('N', 2), 0xFC, 4), 'An RVZ table does not hold all its entries.'],
]);
