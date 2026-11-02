<?php

declare(strict_types=1);

use Boquizo\RaHasher\Disc\Wbfs\WbfsFile;

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wbfs-' . uniqid();
    mkdir($this->directory);
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: []);
    rmdir($this->directory);
});

/**
 * @param  list<int>  $cuts
 */
function wbfsFileOnDisk(string $directory, string $image, array $cuts = []): WbfsFile
{
    return WbfsFile::open(new SplFileInfo(wbfsOnDisk($directory, $image, $cuts)));
}

it('reads the disc through the sector table', function (): void {
    $iso = syntheticWiiDisc();
    $image = wbfsImage($iso);
    $disc = wbfsFileOnDisk($this->directory, $image);

    expect(mb_substr($image, 1 << 18, 0x10000, '8bit'))->toBe(mb_substr($iso, 0x80000, 0x10000, '8bit'))
        ->and($disc->read(0, mb_strlen($iso, '8bit')))->toBe($iso)
        ->and($disc->size())->toBe(WII_DUAL_LAYER_BYTES)
        ->and($disc->read(WII_DUAL_LAYER_BYTES - 4, 4))->toBe("\0\0\0\0");
});

it('reads the first sector of the file for the sectors not stored', function (): void {
    $disc = wbfsFileOnDisk($this->directory, wbfsImage(syntheticWiiDisc()));

    expect($disc->read(3 << 18, 4))->toBe('WBFS');
});

it('reads images split in parts as a single file', function (): void {
    $iso = syntheticWiiDisc();
    $disc = wbfsFileOnDisk($this->directory, wbfsImage($iso), [0x50000, 0xB0000]);

    expect(glob($this->directory . DIRECTORY_SEPARATOR . 'Game.wbf*'))->toHaveCount(3)
        ->and($disc->read(0, mb_strlen($iso, '8bit')))->toBe($iso)
        ->and($disc->read(3 << 18, 4))->toBe('WBFS');
});

it('throws on files that are not wbfs images with a disc', function (Closure $corrupt, string $message): void {
    expect(fn (): WbfsFile => wbfsFileOnDisk($this->directory, $corrupt(wbfsImage(syntheticWiiDisc()))))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'another magic word' => [fn (string $image): string => substr_replace($image, 'WBFX', 0, 4), 'The file is not a WBFS image.'],
    'truncated header' => [fn (string $image): string => mb_substr($image, 0, 12, '8bit'), 'The WBFS header is truncated.'],
    'wbfs sectors of 16 kib' => [fn (string $image): string => substr_replace($image, chr(14), 9, 1), 'The WBFS sector sizes are out of range.'],
    'wbfs sectors of 2 tib' => [fn (string $image): string => substr_replace($image, chr(41), 9, 1), 'The WBFS sector sizes are out of range.'],
    'hard disk sectors of 4 gib' => [fn (string $image): string => substr_replace($image, chr(32), 8, 1), 'The WBFS sector sizes are out of range.'],
    'size not matching the header' => [fn (string $image): string => substr_replace($image, pack('N', 2049), 4, 4), 'The WBFS parts do not add up to the size in its header.'],
    'no disc' => [fn (string $image): string => substr_replace($image, "\0", 12, 1), 'The WBFS image holds no disc.'],
    'truncated sector table' => [fn (string $image): string => substr_replace(mb_substr($image, 0, 0x400, '8bit'), pack('N', 2), 4, 4), 'The WBFS sector table is truncated.'],
]);

it('stops at the first missing part', function (): void {
    $path = wbfsOnDisk($this->directory, wbfsImage(syntheticWiiDisc()), [0x50000, 0xB0000]);
    unlink(mb_substr($path, 0, -1, '8bit') . '1');

    expect(fn (): WbfsFile => WbfsFile::open(new SplFileInfo($path)))
        ->toThrow(RuntimeException::class, 'The WBFS parts do not add up to the size in its header.');
});

it('throws on sectors stored past the end of the file', function (): void {
    $image = substr_replace(wbfsImage(syntheticWiiDisc()), pack('n', 9), 0x200 + 0x100, 2);
    $disc = wbfsFileOnDisk($this->directory, $image);

    expect(fn (): string => $disc->read(0, 4))
        ->toThrow(RuntimeException::class, 'The WBFS sector table points past the end of the file.');
});

it('throws on ranges outside the disc', function (int $offset, int $length): void {
    $disc = wbfsFileOnDisk($this->directory, wbfsImage(syntheticWiiDisc()));

    expect(fn (): string => $disc->read($offset, $length))
        ->toThrow(RuntimeException::class, 'The read lies outside the Wii disc.');
})->with([
    'negative offset' => [-1, 4],
    'negative length' => [0, -1],
    'range past the end' => [WII_DUAL_LAYER_BYTES - 3, 4],
    'offset past the end' => [WII_DUAL_LAYER_BYTES + 1, 0],
]);
