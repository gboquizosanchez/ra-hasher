<?php

declare(strict_types=1);

use Boquizo\RaHasher\Hashers\ArduboyHasher;

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'arduboy-' . uniqid();
    mkdir($this->directory);
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: []);
    rmdir($this->directory);
});

function arduboyFile(string $directory, string $name, string $contents): SplFileInfo
{
    $path = $directory . DIRECTORY_SEPARATOR . $name;
    file_put_contents($path, $contents);

    return new SplFileInfo($path);
}

it('hashes the hex text with unix line endings and a final newline', function (string $contents): void {
    $file = arduboyFile($this->directory, 'Game.hex', $contents);

    expect(new ArduboyHasher()->hash($file))->toBe(md5(":10000000\n:00000001FF\n"));
})->with([
    'windows line endings' => [":10000000\r\n:00000001FF\r\n"],
    'old mac line endings' => [":10000000\r:00000001FF\r"],
    'unix line endings' => [":10000000\n:00000001FF\n"],
    'without final newline' => [":10000000\r\n:00000001FF"],
]);

it('hashes an empty file as a single newline', function (): void {
    $file = arduboyFile($this->directory, 'Empty.hex', '');

    expect(new ArduboyHasher()->hash($file))->toBe(md5("\n"));
});

it('leaves arduboy fx packages to rahasher', function (): void {
    $file = arduboyFile($this->directory, 'Game.arduboy', 'zip data');

    expect(new ArduboyHasher()->hash($file))->toBeNull();
});
