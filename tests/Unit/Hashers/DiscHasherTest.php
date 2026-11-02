<?php

declare(strict_types=1);

use Boquizo\RaHasher\Hashers\PcEngineCdHasher;
use Boquizo\RaHasher\Hashers\PlayStationHasher;

it('opens only the CHD images, whatever the case of the extension', function (string $name, bool $isOpened): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'disc-' . uniqid();
    mkdir($directory);
    $image = cdImageOnDisk($directory, [['MODE1_RAW', 4]], [1 => pcEngineHeader('A CD GAME', 3, 1), 3 => str_repeat('p', 2048)]);
    $path = $directory . DIRECTORY_SEPARATOR . $name;
    rename($image, $path);

    expect((new PcEngineCdHasher())->hash(new SplFileInfo($path)))
        ->toBe($isOpened ? md5('A CD GAME             ' . str_repeat('p', 2048)) : null);

    unlink($path);
    rmdir($directory);
})->with([
    'lowercase extension' => ['Game.chd', true],
    'uppercase extension' => ['Game.CHD', true],
    'cue sheet' => ['Game.cue', false],
    'iso' => ['Game.iso', false],
    'bin' => ['Game.bin', false],
    'no extension' => ['Game', false],
]);

it('hashes the first track of a CHD image unless the system looks elsewhere', function (): void {
    $disc = isoDisc(['PSX.EXE;1' => 'executable']);
    $sectors = array_map(fn (int $sector): string => $disc->readSector($sector, 2048), range(0, 23));
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'disc-' . uniqid();
    mkdir($directory);
    $path = cdImageOnDisk($directory, [['MODE1_RAW', 24]], $sectors);

    expect((new PlayStationHasher())->hash(new SplFileInfo($path)))->toBe(md5('PSX.EXE' . 'executable'));

    unlink($path);
    rmdir($directory);
});
