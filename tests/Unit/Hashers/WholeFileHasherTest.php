<?php

declare(strict_types=1);

use Boquizo\RaHasher\Hashers\WholeFileHasher;

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'whole-file-' . uniqid();
    mkdir($this->directory);
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: []);
    rmdir($this->directory);
});

function wholeFile(string $directory, string $name, string $contents): SplFileInfo
{
    $path = $directory . DIRECTORY_SEPARATOR . $name;
    file_put_contents($path, $contents);

    return new SplFileInfo($path);
}

it('hashes the whole file', function (): void {
    $file = wholeFile($this->directory, 'Sonic.md', 'SEGA MEGA DRIVE rom data');

    expect(new WholeFileHasher()->hash($file))->toBe(md5('SEGA MEGA DRIVE rom data'));
});

it('hashes files bigger than a read chunk', function (): void {
    $contents = str_repeat('0123456789abcdef', 8192);
    $file = wholeFile($this->directory, 'Big.gba', $contents);

    expect(new WholeFileHasher()->hash($file))->toBe(md5($contents));
});

it('hashes only the first 64 MB of the file like rcheevos', function (): void {
    $megabyte = str_repeat("\xAB", 1024 * 1024);
    $path = $this->directory . DIRECTORY_SEPARATOR . 'Big.gba';
    $stream = fopen($path, 'wb');
    $context = hash_init('md5');

    for ($written = 1; $written <= 64; $written++) {
        fwrite($stream, $megabyte);
        hash_update($context, $megabyte);
    }

    fwrite($stream, 'not hashed');
    fclose($stream);
    $file = new SplFileInfo($path);

    expect(new WholeFileHasher()->hash($file))->toBe(hash_final($context));
});

it('hashes empty files', function (): void {
    $file = wholeFile($this->directory, 'Empty.gb', '');

    expect(new WholeFileHasher()->hash($file))->toBe(md5(''));
});

it('leaves playlists to rahasher', function (): void {
    $file = wholeFile($this->directory, 'Sonic CD.M3U', "disc1.chd\n");

    expect(new WholeFileHasher()->hash($file))->toBeNull();
});
