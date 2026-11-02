<?php

declare(strict_types=1);

use Boquizo\RaHasher\Disc\Iso9660;
use Boquizo\RaHasher\Hashers\PlayStationHasher;

/**
 * A PS-X EXE: the 2048 byte header, whose size field at byte 28 does not
 * count itself, and the code.
 */
function psxExecutable(int $codeSize, string $trailer = ''): string
{
    $header = mb_str_pad('PS-X EXE', 28, "\0", STR_PAD_RIGHT, '8bit') . pack('V', $codeSize);

    return mb_str_pad($header, 2048, "\0", STR_PAD_RIGHT, '8bit') . str_repeat('c', $codeSize) . $trailer;
}

it('hashes the boot executable name and contents', function (string $systemConfiguration): void {
    $executable = psxExecutable(4096);
    $track = isoDisc(['SYSTEM.CNF;1' => $systemConfiguration, 'SLUS_123.45;1' => $executable]);

    expect((new PlayStationHasher())->hashTrack($track))->toBe(md5('SLUS_123.45' . $executable));
})->with([
    'prefix, backslash and version' => 'BOOT = cdrom:\\SLUS_123.45;1',
    'no backslash and no version' => 'BOOT=cdrom:SLUS_123.45',
    'spaces and tabs' => "BOOT \t=\t cdrom:\\SLUS_123.45 ;1",
    'several backslashes' => 'BOOT = cdrom:\\\\SLUS_123.45;1',
    'without the prefix' => 'BOOT = SLUS_123.45;1',
    'carriage returns' => "BOOT = cdrom:\\SLUS_123.45;1\r\nTCB = 4\r\n",
    'after other lines' => "TCB = 4\nEVENT = 10\nBOOT = cdrom:\\SLUS_123.45;1\nSTACK = 801FFF00",
    'boot2 line first' => "BOOT2 = cdrom0:\\OTHER.ELF;1\nBOOT = cdrom:\\SLUS_123.45;1",
    'line without equals first' => "BOOTING\nBOOT cdrom:\\OTHER.EXE\nBOOT = cdrom:\\SLUS_123.45;1",
    'name at the end of the file' => 'BOOT = cdrom:\\SLUS_123.45',
    'second boot line after the first one' => "BOOT = cdrom:\\SLUS_123.45;1\0BOOT = cdrom:\\OTHER.EXE",
    'newline before the equals sign' => "BOOT\n= cdrom:\\SLUS_123.45;1",
    'newline after the equals sign' => "BOOT =\ncdrom:\\SLUS_123.45;1",
    'text after a null byte is still scanned' => "TCB = 4\0BOOT = cdrom:\\SLUS_123.45;1",
    'name followed by a null byte' => "BOOT = cdrom:\\SLUS_123.45\0JUNK;1",
]);

it('stops at the second null byte of the line it skips', function (): void {
    $track = isoDisc(['SYSTEM.CNF;1' => "TCB = 4\0\0BOOT = cdrom:\\SLUS_123.45;1", 'SLUS_123.45;1' => 'exe', 'PSX.EXE;1' => 'fallback']);

    expect((new PlayStationHasher())->hashTrack($track))->toBe(md5('PSX.EXE' . 'fallback'));
});

it('hashes the size the executable header declares plus the header', function (): void {
    $executable = psxExecutable(4096, str_repeat('t', 9000));
    $track = isoDisc(['SYSTEM.CNF;1' => 'BOOT = cdrom:\\SLUS_123.45;1', 'SLUS_123.45;1' => $executable]);

    expect((new PlayStationHasher())->hashTrack($track))->toBe(md5('SLUS_123.45' . mb_substr($executable, 0, 2048 + 4096, '8bit')));
});

it('hashes the size of the file when the executable has no PS-X EXE header', function (): void {
    $executable = 'PS-Y EXE' . str_repeat('c', 4500);
    $track = isoDisc(['SYSTEM.CNF;1' => 'BOOT = cdrom:\\SLUS_123.45;1', 'SLUS_123.45;1' => $executable]);

    expect((new PlayStationHasher())->hashTrack($track))->toBe(md5('SLUS_123.45' . $executable));
});

it('hashes what a file that ends in the middle of a sector holds', function (): void {
    $executable = str_repeat('a', 2048) . str_repeat('b', 952);
    $track = isoDisc(['SYSTEM.CNF;1' => 'BOOT = cdrom:\\SLUS_123.45;1', 'SLUS_123.45;1' => $executable]);

    expect((new PlayStationHasher())->hashTrack($track))->toBe(md5('SLUS_123.45' . $executable));
});

it('hashes a file smaller than a sector without the rest of it', function (): void {
    $track = isoDisc(['SYSTEM.CNF;1' => 'BOOT = cdrom:\\SLUS_123.45;1', 'SLUS_123.45;1' => str_repeat('s', 100)]);

    expect((new PlayStationHasher())->hashTrack($track))->toBe(md5('SLUS_123.45' . str_repeat('s', 100)));
});

it('hashes PSX.EXE when SYSTEM.CNF is missing or does not lead to an executable', function (array $files): void {
    $files['PSX.EXE;1'] = 'fallback executable';
    $track = isoDisc($files);

    expect((new PlayStationHasher())->hashTrack($track))->toBe(md5('PSX.EXE' . 'fallback executable'));
})->with([
    'no SYSTEM.CNF' => [[]],
    'no boot line' => [['SYSTEM.CNF;1' => 'TCB = 4']],
    'boot line without equals' => [['SYSTEM.CNF;1' => 'BOOT cdrom:\\SLUS_123.45;1']],
    'boot line skipped with the line it runs into' => [['SYSTEM.CNF;1' => "BOOT \nBOOT = cdrom:\\SLUS_123.45;1", 'SLUS_123.45;1' => 'exe']],
    'boot2 line only' => [['SYSTEM.CNF;1' => 'BOOT2 = cdrom0:\\SLES_123.45;1']],
    'executable not on the disc' => [['SYSTEM.CNF;1' => 'BOOT = cdrom:\\SLUS_123.45;1']],
]);

it('finds no executable to hash', function (array $files): void {
    expect((new PlayStationHasher())->hashTrack(isoDisc($files)))->toBeNull();
})->with([
    'empty disc' => [[]],
    'other files only' => [['SYSTEM.CNF;1' => 'BOOT = cdrom:\\SLUS_123.45;1', 'DATA.BIN' => 'data']],
    'name longer than rcheevos keeps' => [['SYSTEM.CNF;1' => 'BOOT = cdrom:\\' . str_repeat('N', 70), str_repeat('N', 70) => 'exe']],
]);

it('hashes the name that rcheevos keeps of a long one', function (): void {
    $name = str_repeat('N', 63);
    $track = isoDisc(['SYSTEM.CNF;1' => 'BOOT = cdrom:\\' . str_repeat('N', 70), $name => 'exe']);

    expect((new PlayStationHasher())->hashTrack($track))->toBe(md5($name . 'exe'));
});

it('finds nothing to hash when the executable cannot be read', function (): void {
    $track = isoDisc(['SYSTEM.CNF;1' => 'BOOT = cdrom:\\SLUS_123.45;1', 'SLUS_123.45;1' => str_repeat('e', 4000)]);
    $executableSector = Iso9660::findFile($track, 'SLUS_123.45')['sector'];

    expect((new PlayStationHasher())->hashTrack(trackFrom($track, $executableSector)))->toBeNull();
});

it('hashes the sectors an executable has when its image is cut short', function (): void {
    $track = isoDisc(['SYSTEM.CNF;1' => 'BOOT = cdrom:\\SLUS_123.45;1', 'SLUS_123.45;1' => str_repeat('e', 6000)]);
    $executableSector = Iso9660::findFile($track, 'SLUS_123.45')['sector'];

    expect((new PlayStationHasher())->hashTrack(trackFrom($track, $executableSector + 1)))->toBe(md5('SLUS_123.45' . str_repeat('e', 2048)));
});

it('hashes at most 64 MB of the executable', function (): void {
    $executable = 'PS-X EXE' . str_repeat("\0", 20) . pack('V', 0xFFFFF000) . str_repeat("\0", 2016);
    $disc = isoDisc(['SYSTEM.CNF;1' => 'BOOT = cdrom:\\SLUS_123.45;1', 'SLUS_123.45;1' => $executable]);
    $firstFillerSector = Iso9660::findFile($disc, 'SLUS_123.45')['sector'] + 1;

    $expected = hash_init('md5');
    hash_update($expected, 'SLUS_123.45' . $executable);
    for ($sector = 0; $sector < 64 * 1024 * 1024 / 2048 - 1; $sector++) {
        hash_update($expected, str_repeat("\0", 2048));
    }

    expect((new PlayStationHasher())->hashTrack(trackFrom($disc, $firstFillerSector, filler: true)))->toBe(hash_final($expected));
});
