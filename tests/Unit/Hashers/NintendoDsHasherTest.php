<?php

declare(strict_types=1);

use Boquizo\RaHasher\Hashers\NintendoDsHasher;

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nds-' . uniqid();
    mkdir($this->directory);
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: []);
    rmdir($this->directory);
});

/**
 * A 512-byte header pointing to ARM9 code at 0x200, ARM7 code at 0x210 and
 * the icon block at 0x218.
 */
function ndsHeader(int $arm9Size = 16, int $arm7Size = 8): string
{
    $header = str_repeat("\x11", 512);
    $header = substr_replace($header, pack('V', 0x200), 0x20, 4);
    $header = substr_replace($header, pack('V', $arm9Size), 0x2C, 4);
    $header = substr_replace($header, pack('V', 0x210), 0x30, 4);
    $header = substr_replace($header, pack('V', $arm7Size), 0x3C, 4);

    return substr_replace($header, pack('V', 0x218), 0x68, 4);
}

function ndsRom(string $directory, string $contents): SplFileInfo
{
    $path = $directory . DIRECTORY_SEPARATOR . 'Game.nds';
    file_put_contents($path, $contents);

    return new SplFileInfo($path);
}

it('hashes the header, both code blocks and the icon block', function (): void {
    $header = ndsHeader();
    $icon = str_repeat('"', 0xA00);
    $file = ndsRom($this->directory, $header . 'ARM9CODEARM9CODE' . 'ARM7CODE' . $icon . 'rest of the rom');

    expect(new NintendoDsHasher()->hash($file))
        ->toBe(md5(mb_substr($header, 0, 0x160, '8bit') . 'ARM9CODEARM9CODE' . 'ARM7CODE' . $icon));
});

it('pads a short icon block with zeros', function (): void {
    $header = ndsHeader();
    $file = ndsRom($this->directory, $header . 'ARM9CODEARM9CODE' . 'ARM7CODE' . 'ICON');

    expect(new NintendoDsHasher()->hash($file))
        ->toBe(md5(mb_substr($header, 0, 0x160, '8bit') . 'ARM9CODEARM9CODE' . 'ARM7CODE' . mb_str_pad('ICON', 0xA00, "\0", STR_PAD_RIGHT, '8bit')));
});

it('skips a supercard header', function (): void {
    $superCard = substr_replace(substr_replace(str_repeat("\0", 512), "\x2E\x00\x00\xEA", 0, 4), "\x44\x46\x96\x00", 0xB0, 4);
    $header = ndsHeader();
    $icon = str_repeat('"', 0xA00);
    $file = ndsRom($this->directory, $superCard . $header . 'ARM9CODEARM9CODE' . 'ARM7CODE' . $icon);

    expect(new NintendoDsHasher()->hash($file))
        ->toBe(md5(mb_substr($header, 0, 0x160, '8bit') . 'ARM9CODEARM9CODE' . 'ARM7CODE' . $icon));
});

it('hashes roms without arm7 code', function (): void {
    $header = ndsHeader(arm7Size: 0);
    $icon = str_repeat('"', 0xA00);
    $file = ndsRom($this->directory, $header . 'ARM9CODEARM9CODE' . str_repeat("\0", 8) . $icon);

    expect(new NintendoDsHasher()->hash($file))
        ->toBe(md5(mb_substr($header, 0, 0x160, '8bit') . 'ARM9CODEARM9CODE' . $icon));
});

it('leaves files that are not nintendo ds roms to rahasher', function (string $contents): void {
    $file = ndsRom($this->directory, $contents);

    expect(new NintendoDsHasher()->hash($file))->toBeNull();
})->with([
    'shorter than a header' => ['tiny'],
    'code blocks over 16 MB' => [ndsHeader(arm9Size: 16 * 1024 * 1024, arm7Size: 1)],
]);
