<?php

declare(strict_types=1);

use Boquizo\RaHasher\GameHasherRegistry;
use Boquizo\RaHasher\Hashers\WiiHasher;

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wii-' . uniqid();
    mkdir($this->directory);
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: []);
    rmdir($this->directory);
});

function wiiFile(string $directory, string $name, string $contents): SplFileInfo
{
    $path = $directory . DIRECTORY_SEPARATOR . $name;
    file_put_contents($path, $contents);

    return new SplFileInfo($path);
}

function wiiFileAt(string $path): SplFileInfo
{
    return new SplFileInfo($path);
}

it('hashes discs and wiiware titles told apart by their magic word', function (string $name, Closure $contents, string $hash): void {
    $file = wiiFile($this->directory, $name, $contents());

    expect(new WiiHasher()->hash($file))->toBe($hash);
})->with([
    'encrypted iso' => ['Game.iso', fn (): string => syntheticWiiDisc(), SYNTHETIC_WII_HASH],
    'upper case iso' => ['Game.ISO', fn (): string => syntheticWiiDisc(), SYNTHETIC_WII_HASH],
    'decrypted iso' => ['Game.iso', fn (): string => syntheticDecryptedWiiDisc(), SYNTHETIC_DECRYPTED_WII_HASH],
    'wad' => ['Game.wad', fn (): string => syntheticWad(), SYNTHETIC_WAD_HASH],
    'disc named wad' => ['Game.wad', fn (): string => syntheticWiiDisc(), SYNTHETIC_WII_HASH],
    'wad named iso' => ['Game.iso', fn (): string => syntheticWad(), SYNTHETIC_WAD_HASH],
]);

it('hashes wbfs images', function (array $cuts): void {
    $path = wbfsOnDisk($this->directory, wbfsImage(syntheticWiiDisc()), $cuts);

    expect(new WiiHasher()->hash(wiiFileAt($path)))->toBe(SYNTHETIC_WII_HASH);
})->with([
    'whole' => [[]],
    'split in three parts' => [[0x50000, 0xB0000]],
]);

it('leaves files with neither magic word to rahasher', function (string $name): void {
    $file = wiiFile($this->directory, $name, str_repeat("\0", 0x100));

    expect(new WiiHasher()->hash($file))->toBeNull();
})->with(['Game.iso', 'Game.wad']);

it('leaves other containers to rahasher', function (string $name): void {
    $file = wiiFile($this->directory, $name, syntheticWiiDisc());

    expect(GameHasherRegistry::for(19)?->hash($file))->toBeNull();
})->with(['Game.rvz', 'Game.wia', 'Game.gcz', 'Game.ciso', 'Game.wbf1']);

it('leaves truncated wads to rahasher', function (): void {
    $file = wiiFile($this->directory, 'Game.wad', mb_substr(syntheticWad(), 0, 0xE00, '8bit'));

    expect(GameHasherRegistry::for(19)?->hash($file))->toBeNull();
});

it('leaves corrupt or incomplete wbfs images to rahasher', function (string $image, array $cuts, ?string $missing): void {
    $path = wbfsOnDisk($this->directory, $image, $cuts);

    if ($missing !== null) {
        unlink(mb_substr($path, 0, -1, '8bit') . $missing);
    }

    expect(GameHasherRegistry::for(19)?->hash(wiiFileAt($path)))->toBeNull();
})->with([
    'another magic word' => fn (): array => [substr_replace(wbfsImage(syntheticWiiDisc()), 'WBFX', 0, 4), [], null],
    'missing its second part' => fn (): array => [wbfsImage(syntheticWiiDisc()), [0x50000, 0xB0000], '1'],
]);
