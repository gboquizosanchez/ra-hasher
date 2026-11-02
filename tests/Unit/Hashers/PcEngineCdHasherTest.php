<?php

declare(strict_types=1);

use Boquizo\RaHasher\GameHasher;
use Boquizo\RaHasher\Hashers\PcEngineCdHasher;

it('hashes the title and the program sectors of the boot header', function (int $firstSector, int $programSector, int $count): void {
    $sectors = [$firstSector + 1 => pcEngineHeader('TITLE OF THE GAME', $programSector, $count)];
    $program = '';

    for ($index = 0; $index < $count; $index++) {
        $sectors[$firstSector + $programSector + $index] = str_repeat(chr(65 + $index), 2048);
        $program .= $sectors[$firstSector + $programSector + $index];
    }

    expect((new PcEngineCdHasher())->hashTrack(fakeDiscTrack($sectors, $firstSector)))->toBe(md5('TITLE OF THE GAME     ' . $program));
})->with([
    'from the start of the disc' => [0, 20, 3],
    'from a track after the first' => [5, 20, 3],
    'a program sector with three bytes' => [7, 0x010002, 2],
    'no program' => [5, 20, 0],
    'the most sectors' => [5, 20, 255],
]);

it('hashes BOOT.BIN of a GameExpress disc from its own sector, whatever the first sector of the track', function (int $firstSector, int $size): void {
    $content = mb_substr(str_repeat('0123456789', 700), 0, $size, '8bit');
    $disc = trackWithSector(isoDisc(['BOOT.BIN' => $content], $firstSector), $firstSector + 1, str_repeat('x', 128));

    expect((new PcEngineCdHasher())->hashTrack($disc))->toBe(md5($content));
})->with([
    'less than a sector' => [0, 100],
    'one sector' => [5, 2048],
    'a sector and a byte' => [5, 2049],
    'several sectors' => [5, 5000],
]);

it('finds nothing to hash on a disc that is not a PC Engine CD', function (Closure $disc): void {
    expect((new PcEngineCdHasher())->hashTrack($disc()))->toBeNull();
})->with([
    'no second sector' => fn () => fakeDiscTrack([]),
    'second sector cut short' => fn () => fakeDiscTrack([6 => str_repeat('x', 127)], 5),
    'unknown identifier and no BOOT.BIN' => fn () => trackWithSector(isoDisc(['OTHER.BIN' => 'data'], 5), 6, str_repeat('x', 128)),
    'identifier at the wrong place' => fn () => trackWithSector(isoDisc(['OTHER.BIN' => 'data'], 5), 6, 'PC Engine CD-ROM SYSTEM' . str_repeat('x', 105)),
    'no volume' => fn () => fakeDiscTrack([6 => str_repeat('x', 128)], 5),
]);

it('finds nothing to hash when BOOT.BIN is too big', function (): void {
    $sectors = [21 => str_repeat('x', 128), 16 => isoVolumeDescriptor(20, 2048), 20 => isoDirectorySectors([isoRecord('BOOT.BIN', 30, GameHasher::MAX_BUFFER_SIZE)])[0]];

    expect((new PcEngineCdHasher())->hashTrack(fakeDiscTrack($sectors, 20)))->toBeNull();
});

it('finds nothing to hash when the disc cannot be read', function (int $firstMissing): void {
    $sectors = [6 => pcEngineHeader('TITLE', 20, 3), 25 => str_repeat('a', 2048), 26 => str_repeat('b', 2048), 27 => str_repeat('c', 2048)];

    expect((new PcEngineCdHasher())->hashTrack(trackFrom(fakeDiscTrack($sectors, 5), $firstMissing)))->toBeNull();
})->with([
    'the header' => 6,
    'the first program sector' => 25,
    'the last program sector' => 27,
]);

it('finds nothing to hash when BOOT.BIN cannot be read in full', function (int $firstMissing): void {
    $disc = trackWithSector(isoDisc(['BOOT.BIN' => str_repeat('b', 5000)], 5), 6, str_repeat('x', 128));

    expect((new PcEngineCdHasher())->hashTrack(trackFrom($disc, $firstMissing)))->toBeNull();
})->with([
    'the first sector' => 25,
    'the last sector' => 27,
]);

it('hashes the first data track of a CHD image', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pce-' . uniqid();
    mkdir($directory);
    $path = cdImageOnDisk($directory, [['AUDIO', 4], ['MODE1_RAW', 4]], [5 => pcEngineHeader('A CD GAME', 3, 1), 7 => str_repeat('p', 2048)]);

    expect((new PcEngineCdHasher())->hash(new SplFileInfo($path)))->toBe(md5('A CD GAME             ' . str_repeat('p', 2048)));

    unlink($path);
    rmdir($directory);
});
