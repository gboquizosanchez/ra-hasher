<?php

declare(strict_types=1);

use Boquizo\RaHasher\Disc\Iso9660;
use Boquizo\RaHasher\Hashers\PlayStation2Hasher;

it('hashes the boot executable name and contents', function (string $systemConfiguration): void {
    $executable = "\x7fELF" . str_repeat('e', 5000);
    $track = isoDisc(['SYSTEM.CNF;1' => $systemConfiguration, 'SLES_123.45;1' => $executable]);

    expect((new PlayStation2Hasher())->hashTrack($track))->toBe(md5('SLES_123.45' . $executable));
})->with([
    'prefix, backslash and version' => "BOOT2 = cdrom0:\\SLES_123.45;1\r\nVER = 1.00\r\nVMODE = PAL",
    'no backslash and no version' => 'BOOT2=cdrom0:SLES_123.45',
    'spaces and tabs' => "BOOT2 \t=\t cdrom0:\\SLES_123.45 ;1",
    'after other lines' => "VER = 1.00\nBOOT2 = cdrom0:\\SLES_123.45;1",
    'newline before the equals sign' => "BOOT2\n= cdrom0:\\SLES_123.45;1",
    'newline after the equals sign' => "BOOT2 =\ncdrom0:\\SLES_123.45;1",
    'text after a null byte is still scanned' => "VER = 1.00\0BOOT2 = cdrom0:\\SLES_123.45;1",
    'name followed by a null byte' => "BOOT2 = cdrom0:\\SLES_123.45\0JUNK;1",
]);

it('hashes an executable that is not an ELF too', function (): void {
    $track = isoDisc(['SYSTEM.CNF;1' => 'BOOT2 = cdrom0:\\SLES_123.45;1', 'SLES_123.45;1' => 'not an elf']);

    expect((new PlayStation2Hasher())->hashTrack($track))->toBe(md5('SLES_123.45' . 'not an elf'));
});

it('finds no executable to hash', function (array $files): void {
    expect((new PlayStation2Hasher())->hashTrack(isoDisc($files)))->toBeNull();
})->with([
    'no SYSTEM.CNF' => [['PSX.EXE' => 'exe']],
    'no boot2 line' => [['SYSTEM.CNF;1' => 'VER = 1.00']],
    'only a boot line' => [['SYSTEM.CNF;1' => 'BOOT = cdrom:\\SLUS_123.45;1', 'SLUS_123.45;1' => 'exe']],
    'boot2 line without equals' => [['SYSTEM.CNF;1' => 'BOOT2 cdrom0:\\SLES_123.45;1', 'SLES_123.45;1' => 'exe']],
    'boot2 line skipped with the line it runs into' => [['SYSTEM.CNF;1' => "BOOT2 \nBOOT2 = cdrom0:\\SLES_123.45;1", 'SLES_123.45;1' => 'exe']],
    'text after two null bytes' => [['SYSTEM.CNF;1' => "VER = 1.00\0\0BOOT2 = cdrom0:\\SLES_123.45;1", 'SLES_123.45;1' => 'exe']],
    'executable not on the disc' => [['SYSTEM.CNF;1' => 'BOOT2 = cdrom0:\\SLES_123.45;1']],
]);

it('finds nothing to hash when the executable cannot be read', function (int $readableSectors): void {
    $track = isoDisc(['SYSTEM.CNF;1' => 'BOOT2 = cdrom0:\\SLES_123.45;1', 'SLES_123.45;1' => 'exe']);
    $executableSector = Iso9660::findFile($track, 'SLES_123.45')['sector'];

    expect((new PlayStation2Hasher())->hashTrack(trackFrom($track, $executableSector + $readableSectors)))
        ->toBe($readableSectors === 0 ? null : md5('SLES_123.45' . 'exe'));
})->with([0, 1]);

it('hashes at most 64 MB of the executable', function (): void {
    $configuration = 'BOOT2 = cdrom0:\SLES_123.45;1';
    $records = isoRecord('SYSTEM.CNF;1', 21, mb_strlen($configuration, '8bit')) . isoRecord('SLES_123.45;1', 22, 100 * 1024 * 1024);
    $pad = fn (string $data): string => mb_str_pad($data, 2048, "\0", STR_PAD_RIGHT, '8bit');
    $disc = fakeDiscTrack([16 => isoVolumeDescriptor(20, 2048), 20 => $pad($records), 21 => $pad($configuration), 22 => str_repeat('e', 2048)]);

    $expected = hash_init('md5');
    hash_update($expected, 'SLES_123.45' . str_repeat('e', 2048));
    for ($sector = 1; $sector < 64 * 1024 * 1024 / 2048; $sector++) {
        hash_update($expected, str_repeat("\0", 2048));
    }

    expect((new PlayStation2Hasher())->hashTrack(trackFrom($disc, 23, filler: true)))->toBe(hash_final($expected));
});
