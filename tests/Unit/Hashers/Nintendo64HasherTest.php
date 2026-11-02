<?php

declare(strict_types=1);

use Boquizo\RaHasher\Hashers\Nintendo64Hasher;

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'n64-' . uniqid();
    mkdir($this->directory);
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: []);
    rmdir($this->directory);
});

function n64Rom(string $directory, string $name, string $contents): SplFileInfo
{
    $path = $directory . DIRECTORY_SEPARATOR . $name;
    file_put_contents($path, $contents);

    return new SplFileInfo($path);
}

it('hashes every dump as its big endian version', function (string $name, string $contents): void {
    $bigEndian = "\x80\x37\x12\x40" . 'ABCDEFGH';
    $file = n64Rom($this->directory, $name, $contents);

    expect(new Nintendo64Hasher()->hash($file))->toBe(md5($bigEndian));
})->with([
    'z64' => ['Mario.z64', "\x80\x37\x12\x40" . 'ABCDEFGH'],
    'v64' => ['Mario.v64', "\x37\x80\x40\x12" . 'BADCFEHG'],
    'n64' => ['Mario.n64', "\x40\x12\x37\x80" . 'DCBAHGFE'],
]);

it('swaps big dumps across read chunks', function (): void {
    $bigEndian = "\x80\x37\x12\x40" . str_repeat('ABCD', 20000);
    $byteswapped = "\x37\x80\x40\x12" . str_repeat('BADC', 20000);
    $file = n64Rom($this->directory, 'Mario.v64', $byteswapped);

    expect(new Nintendo64Hasher()->hash($file))->toBe(md5($bigEndian));
});

it('swaps words that are not valid characters', function (string $name, string $header, string $words, string $from, string $to): void {
    $file = n64Rom($this->directory, $name, $header . $words);

    $bigEndian = "\x80\x37\x12\x40" . pack($to, ...array_values((array) unpack($from, $words)));

    expect(new Nintendo64Hasher()->hash($file))->toBe(md5($bigEndian));
})->with([
    'v64' => ['Mario.v64', "\x37\x80\x40\x12", "\xD8\x00\xDF\xFF\xFF\xFF\xFF\xFE\xFF\xFF\xFF\xFF", 'n*', 'v*'],
    'n64' => ['Mario.n64', "\x40\x12\x37\x80", "\xD8\x00\xDF\xFF\xFF\xFF\xFF\xFE\x00\x00\x11\x00\x80\x00\x00\x00", 'N*', 'V*'],
    'n64 with the mbstring error marker' => ['Mario.n64', "\x40\x12\x37\x80", "\xD8\x00\xDF\xFF\xFF\xFF\xFF\xFF\x00\x00\x11\x00", 'N*', 'V*'],
]);

it('swaps the last complete 16-bit word of a byteswapped dump like rcheevos', function (): void {
    $file = n64Rom($this->directory, 'Mario.v64', "\x37\x80\x40\x12" . 'ZY');

    expect(new Nintendo64Hasher()->hash($file))->toBe(md5("\x80\x37\x12\x40" . 'YZ'));
});

it('leaves the bytes after the last complete 32-bit word of a little endian dump', function (): void {
    $file = n64Rom($this->directory, 'Mario.n64', "\x40\x12\x37\x80" . 'ZY');

    expect(new Nintendo64Hasher()->hash($file))->toBe(md5("\x80\x37\x12\x40" . 'ZY'));
});

it('hashes 64dd disks without swapping', function (): void {
    $file = n64Rom($this->directory, 'Disk.ndd', "\xE8" . 'disk data');

    expect(new Nintendo64Hasher()->hash($file))->toBe(md5("\xE8" . 'disk data'));
});

it('leaves files that are not nintendo 64 roms to rahasher', function (): void {
    $file = n64Rom($this->directory, 'Readme.txt', 'Not a rom');

    expect(new Nintendo64Hasher()->hash($file))->toBeNull();
});
