<?php

declare(strict_types=1);

use Boquizo\RaHasher\Hashers\ArcadeHasher;

it('hashes the file name without its extension', function (string $path, string $hashed): void {
    $file = new SplFileInfo($path);

    expect(new ArcadeHasher()->hash($file))->toBe(md5($hashed));
})->with([
    'zip' => ['X:/Arcade/galaga.zip', 'galaga'],
    'case sensitive' => ['X:/Arcade/Galaga.7z', 'Galaga'],
    'last extension only' => ['X:/Arcade/sf2.ce.zip', 'sf2.ce'],
    'extracted romset folder' => ['X:/Arcade/galaga', 'galaga'],
]);

it('leaves neo carts and subsystem folders to rahasher', function (string $path): void {
    $file = new SplFileInfo($path);

    expect(new ArcadeHasher()->hash($file))->toBeNull();
})->with([
    'geolith cart' => ['X:/Arcade/mslug.neo'],
    'fbneo subsystem folder' => ['X:/Arcade/MegaDrive/sonic.zip'],
]);
