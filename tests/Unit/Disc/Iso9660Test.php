<?php

declare(strict_types=1);

use Boquizo\RaHasher\Disc\Iso9660;

it('finds a file in the root directory', function (): void {
    $track = isoDisc(['SYSTEM.CNF;1' => 'BOOT = cdrom:\\SLUS_123.45;1', 'PSX.EXE' => str_repeat('x', 5000)]);

    $file = Iso9660::findFile($track, 'PSX.EXE');

    expect($file)->toMatchArray(['size' => 5000])
        ->and($track->readSector($file['sector'], 1))->toBe('x');
});

it('finds files in sub-folders and ignores a leading backslash', function (string $path): void {
    $track = isoDisc(['ROOT.TXT' => 'root', 'PSP_GAME' => ['PARAM.SFO' => 'sfo', 'SYSDIR' => ['EBOOT.BIN' => 'boot']]]);

    $file = Iso9660::findFile($track, $path);

    expect($file)->not->toBeNull()
        ->and($track->readSector($file['sector'], $file['size']))->toBe(str_ends_with($path, 'EBOOT.BIN') ? 'boot' : 'sfo');
})->with([
    'sub-folder' => ['PSP_GAME\\PARAM.SFO'],
    'nested' => ['PSP_GAME\\SYSDIR\\EBOOT.BIN'],
    'leading backslash' => ['\\PSP_GAME\\SYSDIR\\EBOOT.BIN'],
]);

it('compares names ignoring case', function (string $path): void {
    $track = isoDisc(['System.Cnf;1' => 'text', 'Psp_Game' => ['Param.Sfo' => 'sfo']]);

    expect(Iso9660::findFile($track, $path))->not->toBeNull();
})->with(['SYSTEM.CNF', 'system.cnf', 'PSP_GAME\\param.sfo', 'psp_game\\PARAM.SFO']);

it('accepts the version suffix of a file', function (): void {
    $track = isoDisc(['SLUS_123.45;1' => 'exe']);

    expect(Iso9660::findFile($track, 'SLUS_123.45'))->toMatchArray(['size' => 3]);
});

it('does not take a longer name or a suffix other than the version for the file', function (string $path): void {
    $track = isoDisc(['SLUS_123.45;1' => 'exe', 'DATA.BIN.BAK' => 'bak']);

    expect(Iso9660::findFile($track, $path))->toBeNull();
})->with(['SLUS_123', 'SLUS_123.456', 'DATA.BIN', 'SLUS_123.45;2']);

it('does not take a directory for a file in another one', function (): void {
    $track = isoDisc(['FOLDER' => ['INNER.BIN' => 'inner'], 'OTHER.BIN' => 'other']);

    expect(Iso9660::findFile($track, 'INNER.BIN'))->toBeNull()
        ->and(Iso9660::findFile($track, 'FOLDER\\INNER.BIN'))->not->toBeNull()
        ->and(Iso9660::findFile($track, 'MISSING\\INNER.BIN'))->toBeNull()
        ->and(Iso9660::findFile($track, 'FOLDER\\MISSING.BIN'))->toBeNull();
});

it('does not match a directory name with a version suffix', function (): void {
    $track = isoDisc(['FOLDER;1' => ['INNER.BIN' => 'inner']]);

    expect(Iso9660::findFile($track, 'FOLDER\\INNER.BIN'))->toBeNull();
});

it('finds nothing without a primary volume descriptor', function (): void {
    $disc = isoDisc(['A.BIN' => 'a']);
    $sectors = [16 => mb_str_pad("\2CD001", 2048, "\0", STR_PAD_RIGHT, '8bit')];

    expect(Iso9660::findFile(fakeDiscTrack($sectors), 'A.BIN'))->toBeNull()
        ->and(Iso9660::findFile(fakeDiscTrack([]), 'A.BIN'))->toBeNull()
        ->and(Iso9660::findFile($disc, 'A.BIN'))->not->toBeNull();
});

it('finds nothing when the descriptor is cut short', function (): void {
    $sectors = [16 => "\1CD001"];

    expect(Iso9660::findFile(fakeDiscTrack($sectors), 'A.BIN'))->toBeNull();
});

it('looks for the volume descriptor from the first sector of the track', function (): void {
    $track = isoDisc(['A.BIN' => 'a'], firstSector: 150);

    expect(Iso9660::findFile($track, 'A.BIN'))->not->toBeNull();
});

it('finds nothing when the root directory cannot be read', function (): void {
    $track = fakeDiscTrack([16 => isoVolumeDescriptor(99, 2048)]);

    expect(Iso9660::findFile($track, 'A.BIN'))->toBeNull();
});

it('treats a file at sector 0 as missing', function (): void {
    $track = fakeDiscTrack([16 => isoVolumeDescriptor(20, 2048), 20 => mb_str_pad(isoRecord('A.BIN', 0, 1), 2048, "\0", STR_PAD_RIGHT, '8bit')]);

    expect(Iso9660::findFile($track, 'A.BIN'))->toBeNull();
});

it('keeps looking in the next sectors of a directory', function (): void {
    $track = isoDisc(manyFiles());

    expect(Iso9660::findFile($track, 'FILE001.BIN'))->not->toBeNull()
        ->and(Iso9660::findFile($track, 'FILE080.BIN'))->toMatchArray(['size' => 2])
        ->and(Iso9660::findFile($track, 'FILE081.BIN'))->toBeNull();
});

it('stops at the end of a directory that continues in a missing sector', function (): void {
    $disc = isoDisc(manyFiles());
    $track = fakeDiscTrack([16 => $disc->readSector(16, 2048), 20 => $disc->readSector(20, 2048)]);

    expect(Iso9660::findFile($track, 'FILE001.BIN'))->not->toBeNull()
        ->and(Iso9660::findFile($track, 'FILE080.BIN'))->toBeNull();
});

it('keeps the size of the root directory when it moves into a sub-directory, like rcheevos', function (): void {
    $track = isoDisc(['SUB' => manyFiles()]);

    expect(Iso9660::findFile($track, 'SUB\\FILE001.BIN'))->not->toBeNull()
        ->and(Iso9660::findFile($track, 'SUB\\FILE080.BIN'))->toBeNull()
        ->and(Iso9660::findFile(isoDisc(manyFiles()), 'FILE080.BIN'))->not->toBeNull();
});

it('stops at the end of a directory whose sector is cut short', function (): void {
    $sector = mb_str_pad(isoRecord('A.BIN', 30, 4), 2048, "\0", STR_PAD_RIGHT, '8bit');

    expect(Iso9660::findFile(fakeDiscTrack([16 => isoVolumeDescriptor(20, 2048), 20 => $sector]), 'A.BIN'))->not->toBeNull()
        ->and(fn () => Iso9660::findFile(fakeDiscTrack([16 => isoVolumeDescriptor(20, 2048), 20 => mb_substr($sector, 0, 2047, '8bit')]), 'A.BIN'))
        ->toThrow(RuntimeException::class)
        ->and(Iso9660::findFile(fakeDiscTrack([16 => isoVolumeDescriptor(20, 2048)]), 'A.BIN'))->toBeNull();
});

it('takes the records that end at the end of the sector and starts none where the last 34 bytes begin', function (int $start, int $length, string $name, bool $isFound): void {
    $filler = implode('', array_fill(0, $start === 2013 ? 33 : 19, isoRecordOfLength('FILLER', $start === 2013 ? 61 : 106)));
    $sector = mb_str_pad($filler . isoRecordOfLength($name, $length), 2048, "\0", STR_PAD_RIGHT, '8bit');

    expect(Iso9660::findFile(fakeDiscTrack([16 => isoVolumeDescriptor(20, 2048), 20 => $sector]), $name))
        ->toBe($isFound ? ['sector' => 30, 'size' => 4] : null);
})->with([
    'a record starting at the byte 2013 and ending at the byte 2048' => [2013, 35, 'AB', true],
    'a record starting at the byte 2013 and ending at the byte 2049' => [2013, 36, 'AB', false],
    'a record starting at the byte 2014' => [2014, 34, 'A', false],
]);

it('ignores a record that goes past the end of its sector', function (): void {
    $records = '';

    foreach (range(1, 25) as $number) {
        $records .= isoRecord(sprintf('FILLER%034d', $number), 30, 4);
    }

    $overlong = chr(255) . mb_substr(isoRecord('SHORT.BIN', 30, 4), 1, null, '8bit');
    $sector = mb_str_pad($records . $overlong, 2048, "\1", STR_PAD_RIGHT, '8bit');
    $track = fakeDiscTrack([16 => isoVolumeDescriptor(20, 2048), 20 => $sector]);

    expect(Iso9660::findFile($track, sprintf('FILLER%034d', 25)))->not->toBeNull()
        ->and(Iso9660::findFile($track, 'SHORT.BIN'))->toBeNull();
});

/**
 * @return array<string, string>
 */
function manyFiles(): array
{
    $files = [];

    foreach (range(1, 80) as $number) {
        $files[sprintf('FILE%03d.BIN;1', $number)] = sprintf('%02d', $number);
    }

    return $files;
}

/**
 * A directory record of the given length, whatever its name needs.
 */
function isoRecordOfLength(string $name, int $length): string
{
    return mb_str_pad(chr($length) . mb_substr(isoRecord($name, 30, 4), 1, null, '8bit'), $length, "\0", STR_PAD_RIGHT, '8bit');
}
