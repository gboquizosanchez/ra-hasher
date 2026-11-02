<?php

declare(strict_types=1);

use Boquizo\RaHasher\GameHasherRegistry;
use Boquizo\RaHasher\Hashers\GameCubeHasher;

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gamecube-' . uniqid();
    mkdir($this->directory);
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: []);
    rmdir($this->directory);
});

function gameCubeFile(string $directory, string $name, string $contents): SplFileInfo
{
    $path = $directory . DIRECTORY_SEPARATOR . $name;
    file_put_contents($path, $contents);

    return new SplFileInfo($path);
}

it('hashes the synthetic disc like rahasher', function (): void {
    expect(syntheticGameCubeHash(syntheticGameCubeIso()))->toBe(SYNTHETIC_GAME_CUBE_HASH);
});

it('hashes plain disc images', function (string $name): void {
    $file = gameCubeFile($this->directory, $name, syntheticGameCubeIso());

    expect(new GameCubeHasher()->hash($file))->toBe(SYNTHETIC_GAME_CUBE_HASH);
})->with(['Game.iso', 'Game.gcm', 'Game.ISO']);

it('hashes rvz images', function (string $fixture): void {
    $file = new SplFileInfo(rvzFixturePath($fixture));

    expect(new GameCubeHasher()->hash($file))->toBe(SYNTHETIC_GAME_CUBE_HASH);
})->with(['synthetic-zstd', 'synthetic-none']);

it('leaves discs without the gamecube magic word to rahasher', function (): void {
    $file = gameCubeFile($this->directory, 'Game.iso', substr_replace(syntheticGameCubeIso(), 'WII!', 0x1C, 4));

    expect(new GameCubeHasher()->hash($file))->toBeNull();
});

it('leaves other containers to rahasher', function (string $name): void {
    $file = gameCubeFile($this->directory, $name, syntheticGameCubeIso());

    expect(GameHasherRegistry::for(16)?->hash($file))->toBeNull();
})->with(['Game.wia', 'Game.gcz', 'Game.ciso']);

it('leaves rvz images compressed with other methods to rahasher', function (): void {
    $rvz = substr_replace((string) file_get_contents(rvzFixturePath('synthetic-none')), pack('N', 3), 0x4C, 4);
    $file = gameCubeFile($this->directory, 'Game.rvz', $rvz);

    expect(GameHasherRegistry::for(16)?->hash($file))->toBeNull();
});
