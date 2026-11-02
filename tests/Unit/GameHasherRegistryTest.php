<?php

declare(strict_types=1);

use Boquizo\RaHasher\GameHasherRegistry;
use Boquizo\RaHasher\Hashers\ArcadeHasher;
use Boquizo\RaHasher\Hashers\ArduboyHasher;
use Boquizo\RaHasher\Hashers\DreamcastHasher;
use Boquizo\RaHasher\Hashers\GameCubeHasher;
use Boquizo\RaHasher\Hashers\HeaderSkippingHasher;
use Boquizo\RaHasher\Hashers\NeoGeoCdHasher;
use Boquizo\RaHasher\Hashers\Nintendo64Hasher;
use Boquizo\RaHasher\Hashers\NintendoDsHasher;
use Boquizo\RaHasher\Hashers\PcEngineCdHasher;
use Boquizo\RaHasher\Hashers\PcFxHasher;
use Boquizo\RaHasher\Hashers\PlayStation2Hasher;
use Boquizo\RaHasher\Hashers\PlayStationHasher;
use Boquizo\RaHasher\Hashers\PlayStationPortableHasher;
use Boquizo\RaHasher\Hashers\SegaCdHasher;
use Boquizo\RaHasher\Hashers\ThreeDoHasher;
use Boquizo\RaHasher\Hashers\WholeFileHasher;
use Boquizo\RaHasher\Hashers\WiiHasher;

it('hashes each supported system with its method', function (int $systemId, string $hasher): void {
    expect(GameHasherRegistry::for($systemId)?->inner())->toBeInstanceOf($hasher);
})->with([
    'nintendo 64' => [2, Nintendo64Hasher::class],
    'snes' => [3, HeaderSkippingHasher::class],
    'nes' => [7, HeaderSkippingHasher::class],
    'famicom disk system' => [81, HeaderSkippingHasher::class],
    'pc engine' => [8, HeaderSkippingHasher::class],
    'atari lynx' => [13, HeaderSkippingHasher::class],
    'atari 7800' => [51, HeaderSkippingHasher::class],
    'nintendo ds' => [18, NintendoDsHasher::class],
    'nintendo dsi' => [78, NintendoDsHasher::class],
    'gamecube' => [16, GameCubeHasher::class],
    'wii' => [19, WiiHasher::class],
    'arcade' => [27, ArcadeHasher::class],
    'arduboy' => [71, ArduboyHasher::class],
    'playstation' => [12, PlayStationHasher::class],
    'playstation 2' => [21, PlayStation2Hasher::class],
    'psp' => [41, PlayStationPortableHasher::class],
    'sega cd' => [9, SegaCdHasher::class],
    'sega saturn' => [39, SegaCdHasher::class],
    '3do' => [43, ThreeDoHasher::class],
    'neo geo cd' => [56, NeoGeoCdHasher::class],
    'pc engine cd' => [76, PcEngineCdHasher::class],
    'pc-fx' => [49, PcFxHasher::class],
    'dreamcast' => [40, DreamcastHasher::class],
]);

it('hashes the cartridge and disk systems as whole files', function (int $systemId): void {
    expect(GameHasherRegistry::for($systemId)?->inner())->toBeInstanceOf(WholeFileHasher::class);
})->with([
    'genesis' => 1,
    'game boy' => 4,
    'game boy advance' => 5,
    'game boy color' => 6,
    '32x' => 10,
    'master system' => 11,
    'neo geo pocket' => 14,
    'game gear' => 15,
    'atari jaguar' => 17,
    'magnavox odyssey 2' => 23,
    'pokemon mini' => 24,
    'atari 2600' => 25,
    'virtual boy' => 28,
    'msx' => 29,
    'sg-1000' => 33,
    'amstrad cpc' => 37,
    'apple ii' => 38,
    'colecovision' => 44,
    'intellivision' => 45,
    'vectrex' => 46,
    'pc-8000/8800' => 47,
    'wonderswan' => 53,
    'fairchild channel f' => 57,
    'watara supervision' => 63,
    'mega duck' => 69,
    'wasm-4' => 72,
    'arcadia 2001' => 73,
    'interton vc 4000' => 74,
    'elektor tv games computer' => 75,
    'uzebox' => 80,
]);

it('leaves the other systems to rahasher', function (int $systemId): void {
    expect(GameHasherRegistry::for($systemId))->toBeNull();
})->with([
    'nintendo 3ds' => 62,
]);

it('leaves zip files to rahasher except for arcade', function (int $systemId, string $name, bool $isNative): void {
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid() . '-' . $name;
    file_put_contents($path, 'zip container');
    $file = new SplFileInfo($path);

    expect(GameHasherRegistry::for($systemId)?->hash($file) !== null)->toBe($isNative);

    unlink($path);
})->with([
    'gba zip' => [5, 'Game.zip', false],
    'gba upper zip' => [5, 'Game.ZIP', false],
    'nes zip' => [7, 'Game.zip', false],
    'nes upper zip' => [7, 'Game.ZIP', false],
    'arcade zip' => [27, 'galaga.zip', true],
]);

it('leaves files that cannot be opened to rahasher', function (int $systemId): void {
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'missing-' . uniqid() . '.gba';
    $file = new SplFileInfo($path);

    expect(GameHasherRegistry::for($systemId)?->hash($file))->toBeNull();
})->with([
    'whole file' => 5,
    'header skipping' => 7,
    'nintendo 64' => 2,
]);
