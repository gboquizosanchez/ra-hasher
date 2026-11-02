<?php

declare(strict_types=1);

use Boquizo\RaHasher\Hashers\PcFxHasher;

/** The first sector of a PC-FX track: the identifier, then filler. */
function pcFxIdentifierSector(): string
{
    return mb_str_pad('PC-FX:Hu_CD-ROM', 2048, 'f', STR_PAD_RIGHT, '8bit');
}

/** The 128 bytes after the first sector: the program sector (little endian at 32) and its sector count (at 36). */
function pcFxHeader(int $sector, int $count): string
{
    $header = mb_str_pad('', 32, 'h', STR_PAD_RIGHT, '8bit') . mb_substr(pack('V', $sector), 0, 3, '8bit') . 'h' . mb_substr(pack('V', $count), 0, 3, '8bit');

    return mb_str_pad($header, 128, 'h', STR_PAD_RIGHT, '8bit');
}

it('hashes the boot header and the program sectors', function (int $firstSector, int $programSector, int $count): void {
    $header = pcFxHeader($programSector, $count);
    $sectors = [$firstSector => pcFxIdentifierSector(), $firstSector + 1 => $header];
    $program = '';

    for ($index = 0; $index < $count; $index++) {
        $sectors[$firstSector + $programSector + $index] = str_repeat(chr(65 + $index), 2048);
        $program .= $sectors[$firstSector + $programSector + $index];
    }

    expect((new PcFxHasher())->hashTrack(fakeDiscTrack($sectors, $firstSector)))->toBe(md5($header . $program));
})->with([
    'from the start of the disc' => [0, 20, 3],
    'from a track after the first' => [5, 20, 3],
    'a program sector with three bytes' => [7, 0x010002, 2],
    'a sector count with three bytes' => [0, 4, 0x000102],
    'no program' => [5, 20, 0],
]);

it('hashes a PC Engine CD that is on a PC-FX disc as such', function (int $firstSector): void {
    $sectors = [$firstSector + 1 => pcEngineHeader('TITLE OF THE GAME', 20, 2), $firstSector + 20 => str_repeat('a', 2048), $firstSector + 21 => str_repeat('b', 2048)];

    expect((new PcFxHasher())->hashTrack(fakeDiscTrack($sectors, $firstSector)))->toBe(md5('TITLE OF THE GAME     ' . str_repeat('a', 2048) . str_repeat('b', 2048)));
})->with([0, 5]);

it('finds nothing to hash on a disc that is neither a PC-FX nor a PC Engine CD', function (Closure $disc): void {
    expect((new PcFxHasher())->hashTrack($disc()))->toBeNull();
})->with([
    'no second sector' => fn () => fakeDiscTrack([5 => pcFxIdentifierSector()], 5),
    'second sector cut short' => fn () => fakeDiscTrack([5 => pcFxIdentifierSector(), 6 => str_repeat('h', 127)], 5),
    'no identifier at all' => fn () => fakeDiscTrack([6 => str_repeat('h', 128)], 5),
    'identifier cut short' => fn () => fakeDiscTrack([5 => 'PC-FX:Hu_CD-RO', 6 => str_repeat('h', 128)], 5),
    'a PC Engine CD whose program cannot be read' => fn () => fakeDiscTrack([6 => pcEngineHeader('TITLE', 20, 1)], 5),
    'a BOOT.BIN is not enough' => fn () => trackWithSector(isoDisc(['BOOT.BIN' => 'data'], 5), 6, str_repeat('x', 128)),
]);

it('finds nothing to hash when the program of the PC-FX cannot be read', function (int $firstMissing): void {
    $sectors = [5 => pcFxIdentifierSector(), 6 => pcFxHeader(20, 3), 25 => str_repeat('a', 2048), 26 => str_repeat('b', 2048), 27 => str_repeat('c', 2048)];

    expect((new PcFxHasher())->hashTrack(trackFrom(fakeDiscTrack($sectors, 5), $firstMissing)))->toBeNull();
})->with([
    'the header' => 6,
    'the first program sector' => 25,
    'the last program sector' => 27,
]);

/**
 * Hashes a CHD image of the tracks, with the sectors by absolute number.
 *
 * @param  list<array{string, int}>  $tracks
 * @param  array<int, string>  $sectors
 */
function hashPcFxImage(array $tracks, array $sectors): ?string
{
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pcfx-' . uniqid();
    mkdir($directory);
    $path = cdImageOnDisk($directory, $tracks, $sectors);

    try {
        return (new PcFxHasher())->hash(new SplFileInfo($path));
    } finally {
        unlink($path);
        rmdir($directory);
    }
}

it('looks for the PC-FX in the largest data track of a CHD image', function (): void {
    $header = pcFxHeader(2, 1);

    expect(hashPcFxImage([['MODE1_RAW', 4], ['MODE1_RAW', 8]], [4 => pcFxIdentifierSector(), 5 => $header, 6 => str_repeat('p', 2048)]))->toBe(md5($header . str_repeat('p', 2048)));
});

it('looks for the PC-FX in track 2 when the largest data track does not hold it', function (): void {
    $header = pcFxHeader(2, 1);
    $sectors = [4 => pcFxIdentifierSector(), 5 => $header, 6 => str_repeat('p', 2048), 8 => 'not a PC-FX'];

    expect(hashPcFxImage([['MODE1_RAW', 4], ['MODE1_RAW', 4], ['MODE1_RAW', 8]], $sectors))->toBe(md5($header . str_repeat('p', 2048)));
});

it('hashes a PC Engine CD that is in track 2 of a CHD image', function (): void {
    $sectors = [5 => pcEngineHeader('A CD GAME', 3, 1), 7 => str_repeat('p', 2048), 8 => 'not a PC-FX'];

    expect(hashPcFxImage([['MODE1_RAW', 4], ['MODE1_RAW', 4], ['MODE1_RAW', 8]], $sectors))->toBe(md5('A CD GAME             ' . str_repeat('p', 2048)));
});

it('finds nothing to hash when the PC-FX is in neither of the tracks', function (): void {
    expect(hashPcFxImage([['MODE1_RAW', 4], ['MODE1_RAW', 4], ['MODE1_RAW', 8]], [0 => pcFxIdentifierSector(), 1 => pcFxHeader(2, 1)]))->toBeNull();
});

it('cannot open a CHD image that has no track 2 when the largest data track does not hold the PC-FX', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pcfx-' . uniqid();
    mkdir($directory);
    $path = cdImageOnDisk($directory, [['MODE1_RAW', 8]], []);

    expect(fn (): ?string => (new PcFxHasher())->hash(new SplFileInfo($path)))->toThrow(RuntimeException::class, 'has no track 2');

    unlink($path);
    rmdir($directory);
});
