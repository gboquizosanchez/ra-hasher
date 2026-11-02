<?php

declare(strict_types=1);

use Boquizo\RaHasher\Disc\DiscTrack;
use Boquizo\RaHasher\Hashers\DreamcastHasher;

/** The first 256 bytes of a track: the marker, filler up to the boot name (at 96) and the name, blank padded. */
function dreamcastIpBin(string $bootName = '1ST_READ.BIN', string $marker = 'SEGA SEGAKATANA '): string
{
    return mb_str_pad($marker, 96, 'i', STR_PAD_RIGHT, '8bit') . mb_str_pad($bootName, 160, ' ', STR_PAD_RIGHT, '8bit');
}

/**
 * A track with the IP.BIN in its first sector and a volume holding the files.
 *
 * @param  array<string, string>  $files
 */
function dreamcastTrack(array $files, int $firstSector = 0, string $ipBin = ''): DiscTrack
{
    return trackWithSector(isoDisc($files, $firstSector), $firstSector, $ipBin === '' ? dreamcastIpBin() : $ipBin);
}

/**
 * The sectors of a track, by absolute number, for a CHD image.
 *
 * @return array<int, string>
 */
function dreamcastSectors(DiscTrack $track, int $count): array
{
    $sectors = [];

    for ($sector = 0; $sector < $count; $sector++) {
        $sectors[$sector] = $track->readSector($sector, 2048);
    }

    return $sectors;
}

/**
 * Hashes a CHD image of the tracks, with the sectors by absolute number.
 *
 * @param  list<array{string, int}>  $tracks
 * @param  array<int, string>  $sectors
 */
function hashDreamcastImage(array $tracks, array $sectors): ?string
{
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dreamcast-' . uniqid();
    mkdir($directory);
    $path = cdImageOnDisk($directory, $tracks, $sectors);

    try {
        return (new DreamcastHasher())->hash(new SplFileInfo($path));
    } finally {
        unlink($path);
        rmdir($directory);
    }
}

it('hashes the IP.BIN and the boot executable', function (int $firstSector, int $size): void {
    $executable = mb_str_pad('', $size, 'e', STR_PAD_RIGHT, '8bit');

    expect((new DreamcastHasher())->hashTrack(dreamcastTrack(['1ST_READ.BIN' => $executable], $firstSector)))
        ->toBe(md5(dreamcastIpBin() . $executable));
})->with([
    'from the start of the disc' => [0, 3000],
    'from a track after the first' => [5, 3000],
    'an executable of a sector' => [5, 2048],
    'an executable of a few bytes' => [5, 10],
]);

it('takes the boot executable name up to the first blank', function (string $bootName, string $file): void {
    expect((new DreamcastHasher())->hashTrack(dreamcastTrack([$file => 'exe'], 0, dreamcastIpBin($bootName))))
        ->toBe(md5(dreamcastIpBin($bootName) . 'exe'));
})->with([
    'a space' => ['A.BIN B.BIN', 'A.BIN'],
    'a tab' => ["A.BIN\tB.BIN", 'A.BIN'],
    'a line feed' => ["A.BIN\nB.BIN", 'A.BIN'],
    'a vertical tab' => ["A.BIN\vB.BIN", 'A.BIN'],
    'a form feed' => ["A.BIN\fB.BIN", 'A.BIN'],
    'a carriage return' => ["A.BIN\rB.BIN", 'A.BIN'],
    'sixteen characters without a blank' => ['ABCDEFGHIJKL.BIN' . 'X', 'ABCDEFGHIJKL.BIN'],
    'a NUL' => ["A.BIN\0B.BIN", 'A.BIN'],
]);

it('finds nothing to hash on a track that is not an IP.BIN with a boot executable', function (Closure $track): void {
    expect((new DreamcastHasher())->hashTrack($track()))->toBeNull();
})->with([
    'no marker' => fn () => dreamcastTrack(['1ST_READ.BIN' => 'exe'], 0, dreamcastIpBin('1ST_READ.BIN', 'SEGA SEGAKATANB ')),
    'a short IP.BIN' => fn () => trackWithSector(dreamcastTrack(['1ST_READ.BIN' => 'exe']), 0, mb_substr(dreamcastIpBin(), 0, 255, '8bit')),
    'no first sector' => fn () => isoDisc(['1ST_READ.BIN' => 'exe']),
    'a blank boot name' => fn () => dreamcastTrack(['1ST_READ.BIN' => 'exe'], 0, dreamcastIpBin('')),
    'a boot name that starts with a NUL' => fn () => dreamcastTrack(['1ST_READ.BIN' => 'exe'], 0, dreamcastIpBin("\0A.BIN")),
    'an executable that is not on the disc' => fn () => dreamcastTrack(['OTHER.BIN' => 'exe']),
    'an executable that cannot be read' => fn () => trackFrom(dreamcastTrack(['1ST_READ.BIN' => 'exe']), 21),
]);

it('hashes the IP.BIN of the GD-ROM data track with an executable in the same track', function (): void {
    $disc = dreamcastTrack(['1ST_READ.BIN' => str_repeat('e', 3000)], 8);
    $sectors = dreamcastSectors($disc, 40);

    expect(hashDreamcastImage([['MODE1_RAW', 4], ['MODE1_RAW', 4], ['MODE1_RAW', 32], ['MODE1_RAW', 4]], $sectors))
        ->toBe(md5(dreamcastIpBin() . str_repeat('e', 3000)));
});

it('hashes the boot executable from the last track when the data track stops before it', function (): void {
    $disc = dreamcastTrack(['1ST_READ.BIN' => str_repeat('e', 3000)], 8);
    $sectors = dreamcastSectors($disc, 36);

    expect(hashDreamcastImage([['MODE1_RAW', 4], ['MODE1_RAW', 4], ['MODE1_RAW', 20], ['MODE1_RAW', 8]], $sectors))
        ->toBe(md5(dreamcastIpBin() . str_repeat('e', 3000)));
});

it('finds the IP.BIN of a MIL-CD in the first data track', function (array $tracks): void {
    $sectors = dreamcastSectors(dreamcastTrack(['1ST_READ.BIN' => str_repeat('e', 3000)]), 24);

    expect(hashDreamcastImage($tracks, $sectors))->toBe(md5(dreamcastIpBin() . str_repeat('e', 3000)));
})->with([
    'a single track' => [[['MODE1_RAW', 24]]],
    'a third track without the marker' => [[['MODE1_RAW', 24], ['AUDIO', 4], ['MODE1_RAW', 4]]],
    'a third track that is audio' => [[['MODE1_RAW', 24], ['AUDIO', 4], ['AUDIO', 4]]],
]);

it('finds nothing to hash on an image without the marker in either track', function (): void {
    $disc = dreamcastTrack(['1ST_READ.BIN' => 'exe'], 0, dreamcastIpBin('1ST_READ.BIN', 'not a dreamcast'));

    expect(hashDreamcastImage([['MODE1_RAW', 24], ['MODE1_RAW', 4], ['MODE1_RAW', 4]], dreamcastSectors($disc, 24)))->toBeNull();
});

it('cannot open an image that has no data track', function (): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dreamcast-' . uniqid();
    mkdir($directory);
    $path = cdImageOnDisk($directory, [['AUDIO', 4]], []);

    expect(fn (): ?string => (new DreamcastHasher())->hash(new SplFileInfo($path)))->toThrow(RuntimeException::class, 'FirstData');

    unlink($path);
    rmdir($directory);
});
