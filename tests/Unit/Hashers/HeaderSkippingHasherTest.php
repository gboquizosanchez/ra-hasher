<?php

declare(strict_types=1);

use Boquizo\RaHasher\Hashers\HeaderSkippingHasher;

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'header-skipping-' . uniqid();
    mkdir($this->directory);
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: []);
    rmdir($this->directory);
});

function headeredRom(string $directory, string $contents): SplFileInfo
{
    $path = $directory . DIRECTORY_SEPARATOR . 'rom.bin';
    file_put_contents($path, $contents);

    return new SplFileInfo($path);
}

it('skips the header of the roms that have one', function (HeaderSkippingHasher $hasher, string $header): void {
    $rom = str_repeat("\xAB", 300);
    $file = headeredRom($this->directory, $header . $rom);

    expect($hasher->hash($file))->toBe(md5($rom));
})->with([
    'nes' => [HeaderSkippingHasher::nes(), "NES\x1a" . str_repeat("\0", 12)],
    'famicom disk system' => [HeaderSkippingHasher::nes(), "FDS\x1a" . str_repeat("\0", 12)],
    'atari 7800' => [HeaderSkippingHasher::atari7800(), "\x01ATARI7800" . str_repeat("\0", 118)],
    'atari lynx' => [HeaderSkippingHasher::lynx(), "LYNX\0" . str_repeat("\0", 59)],
]);

it('hashes the whole rom when it has no header', function (HeaderSkippingHasher $hasher): void {
    $rom = str_repeat("\xAB", 300);
    $file = headeredRom($this->directory, $rom);

    expect($hasher->hash($file))->toBe(md5($rom));
})->with([
    'nes' => [HeaderSkippingHasher::nes()],
    'atari 7800' => [HeaderSkippingHasher::atari7800()],
    'atari lynx' => [HeaderSkippingHasher::lynx()],
]);

it('hashes the whole file when it is not bigger than the header', function (HeaderSkippingHasher $hasher, string $header): void {
    $file = headeredRom($this->directory, $header);

    expect($hasher->hash($file))->toBe(md5($header));
})->with([
    'nes' => [HeaderSkippingHasher::nes(), "NES\x1a" . str_repeat("\0", 12)],
    'atari 7800' => [HeaderSkippingHasher::atari7800(), "\x01ATARI7800" . str_repeat("\0", 118)],
    'atari lynx' => [HeaderSkippingHasher::lynx(), "LYNX\0" . str_repeat("\0", 59)],
]);

it('skips the header of a rom just one byte bigger than it', function (HeaderSkippingHasher $hasher, string $header): void {
    $file = headeredRom($this->directory, $header . "\xAB");

    expect($hasher->hash($file))->toBe(md5("\xAB"));
})->with([
    'nes' => [HeaderSkippingHasher::nes(), "NES\x1a" . str_repeat("\0", 12)],
    'atari 7800' => [HeaderSkippingHasher::atari7800(), "\x01ATARI7800" . str_repeat("\0", 118)],
    'atari lynx' => [HeaderSkippingHasher::lynx(), "LYNX\0" . str_repeat("\0", 59)],
]);

it('looks for the atari 7800 signature from the second byte', function (): void {
    $rom = 'ATARI7800' . str_repeat("\0", 300);
    $file = headeredRom($this->directory, $rom);

    expect(HeaderSkippingHasher::atari7800()->hash($file))->toBe(md5($rom));
});

it('skips the snes copier header of roms 512 bytes over a multiple of 8 KB', function (): void {
    $header = str_repeat("\0", 512);
    $rom = str_repeat("\xCD", 0x2000);
    $file = headeredRom($this->directory, $header . $rom);

    expect(HeaderSkippingHasher::snes()->hash($file))->toBe(md5($rom));
});

it('hashes snes roms without copier header entirely', function (): void {
    $rom = str_repeat("\xCD", 0x2000);
    $file = headeredRom($this->directory, $rom);

    expect(HeaderSkippingHasher::snes()->hash($file))->toBe(md5($rom));
});

it('hashes a snes rom of only a copier header as empty', function (): void {
    $file = headeredRom($this->directory, str_repeat("\0", 512));

    expect(HeaderSkippingHasher::snes()->hash($file))->toBe(md5(''));
});

it('hashes snes roms with other sizes entirely', function (int $size): void {
    $rom = str_repeat("\xCD", $size);
    $file = headeredRom($this->directory, $rom);

    expect(HeaderSkippingHasher::snes()->hash($file))->toBe(md5($rom));
})->with([
    'one byte less' => 0x2000 + 511,
    'one byte more' => 0x2000 + 513,
]);

it('skips the pc engine header when the 512 bit of the size is set', function (int $romSize): void {
    $rom = str_repeat("\xEF", $romSize);
    $file = headeredRom($this->directory, str_repeat("\0", 512) . $rom);

    expect(HeaderSkippingHasher::pcEngine()->hash($file))->toBe(md5($rom));
})->with([
    'multiple of 8 KB' => 0x2000,
    'not a multiple of 8 KB' => 0x2000 + 1024,
    'only the header' => 0,
]);

it('hashes pc engine roms without the 512 bit of the size entirely', function (int $size): void {
    $rom = str_repeat("\xEF", $size);
    $file = headeredRom($this->directory, $rom);

    expect(HeaderSkippingHasher::pcEngine()->hash($file))->toBe(md5($rom));
})->with([
    'multiple of 8 KB' => 0x2000,
    'one byte less than the header' => 511,
    '1 KB over a multiple of 8 KB' => 0x2000 + 1024 + 511,
]);

it('hashes only the first 64 MB of the file like rcheevos', function (): void {
    $header = "NES\x1a" . str_repeat("\0", 12);
    $megabyte = str_repeat("\xAB", 1024 * 1024);
    $path = $this->directory . DIRECTORY_SEPARATOR . 'rom.bin';
    $stream = fopen($path, 'wb');
    $context = hash_init('md5');
    fwrite($stream, $header);

    for ($written = 1; $written <= 64; $written++) {
        fwrite($stream, $megabyte);
        $hashed = $written < 64 ? $megabyte : str_repeat("\xAB", 1024 * 1024 - 16);
        hash_update($context, $hashed);
    }

    fclose($stream);
    $file = new SplFileInfo($path);

    expect(HeaderSkippingHasher::nes()->hash($file))->toBe(hash_final($context));
});

it('hashes an empty rom', function (): void {
    $file = headeredRom($this->directory, '');

    expect(HeaderSkippingHasher::snes()->hash($file))->toBe(md5(''));
});
