<?php

declare(strict_types=1);

use Boquizo\RaHasher\Disc\DiscTrack;
use Boquizo\RaHasher\Disc\Iso9660;
use Boquizo\RaHasher\Hashers\PlayStationPortableHasher;

/**
 * @return array<string, mixed>
 */
function pspDiscFiles(string $paramSfo, string $ebootBin): array
{
    return ['PSP_GAME' => ['PARAM.SFO' => $paramSfo, 'SYSDIR' => ['EBOOT.BIN' => $ebootBin]], 'UMD_DATA.BIN' => 'ULUS-12345|0000'];
}

it('hashes PARAM.SFO and EBOOT.BIN without their names', function (): void {
    $paramSfo = "\0PSF" . str_repeat('p', 3000);
    $ebootBin = '~PSP' . str_repeat('b', 5000);
    $track = isoDisc(pspDiscFiles($paramSfo, $ebootBin));

    expect((new PlayStationPortableHasher())->hashTrack($track))->toBe(md5($paramSfo . $ebootBin));
});

it('compares the names of the files ignoring case', function (): void {
    $track = isoDisc(['psp_game' => ['param.sfo;1' => 'sfo', 'SysDir' => ['Eboot.Bin;1' => 'boot']]]);

    expect((new PlayStationPortableHasher())->hashTrack($track))->toBe(md5('sfo' . 'boot'));
});

it('finds no PSP game to hash', function (array $files): void {
    expect((new PlayStationPortableHasher())->hashTrack(isoDisc($files)))->toBeNull();
})->with([
    'empty disc' => [[]],
    'no PSP_GAME folder' => [['PARAM.SFO' => 'sfo', 'EBOOT.BIN' => 'boot']],
    'no PARAM.SFO' => [['PSP_GAME' => ['SYSDIR' => ['EBOOT.BIN' => 'boot']]]],
    'no SYSDIR folder' => [['PSP_GAME' => ['PARAM.SFO' => 'sfo']]],
    'no EBOOT.BIN' => [['PSP_GAME' => ['PARAM.SFO' => 'sfo', 'SYSDIR' => ['OTHER.BIN' => 'boot']]]],
]);

it('finds nothing to hash when a file cannot be read', function (string $path): void {
    $track = isoDisc(pspDiscFiles(str_repeat('p', 3000), 'boot'));
    $unreadableSector = Iso9660::findFile($track, $path)['sector'];

    expect((new PlayStationPortableHasher())->hashTrack(trackFrom($track, $unreadableSector)))->toBeNull();
})->with(['PSP_GAME\\PARAM.SFO', 'PSP_GAME\\SYSDIR\\EBOOT.BIN']);

it('hashes at most 64 MB of each file', function (): void {
    $pad = fn (string $data): string => mb_str_pad($data, 2048, "\0", STR_PAD_RIGHT, '8bit');
    $big = 100 * 1024 * 1024;
    $sectors = [
        16 => isoVolumeDescriptor(20, 2048),
        20 => $pad(isoRecord('PSP_GAME', 21, 2048, true)),
        21 => $pad(isoRecord('PARAM.SFO', 30000, $big) . isoRecord('SYSDIR', 23, 2048, true)),
        30000 => str_repeat('s', 2048),
        23 => $pad(isoRecord('EBOOT.BIN', 70000, $big)),
        70000 => str_repeat('b', 2048),
    ];
    $track = zeroFilledTrack(fakeDiscTrack($sectors));

    $expected = hash_init('md5');
    foreach (['s', 'b'] as $letter) {
        hash_update($expected, str_repeat($letter, 2048));
        for ($sector = 1; $sector < 64 * 1024 * 1024 / 2048; $sector++) {
            hash_update($expected, str_repeat("\0", 2048));
        }
    }

    expect((new PlayStationPortableHasher())->hashTrack($track))->toBe(hash_final($expected));
});

/**
 * The track with zeros in place of the sectors it lacks.
 */
function zeroFilledTrack(DiscTrack $track): DiscTrack
{
    return new readonly class($track) implements DiscTrack
    {
        public function __construct(private DiscTrack $track) {}

        public function readSector(int $sector, int $bytes): string
        {
            $data = $this->track->readSector($sector, $bytes);

            return $data === '' ? str_repeat("\0", $bytes) : $data;
        }

        public function firstSector(): int
        {
            return $this->track->firstSector();
        }
    };
}
