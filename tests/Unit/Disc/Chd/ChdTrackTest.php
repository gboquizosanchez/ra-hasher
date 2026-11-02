<?php

declare(strict_types=1);

use Boquizo\RaHasher\Disc\Chd\ChdFile;
use Boquizo\RaHasher\Disc\Chd\ChdMapEntry;
use Boquizo\RaHasher\Disc\Chd\ChdTrack;
use Boquizo\RaHasher\Disc\Chd\Crc16;
use Boquizo\RaHasher\Disc\SpecialTrack;

const CD_FRAME_BYTES = 2448;

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'chd-track-' . uniqid();
    mkdir($this->directory);
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: []);
    rmdir($this->directory);
});

/**
 * Frames whose every 16 bytes differ, so any offset into them tells where it was read.
 */
function distinctCdFrames(int $frameCount, int $frameBytes = CD_FRAME_BYTES): string
{
    $frames = '';

    for ($frame = 0; $frame < $frameCount; $frame++) {
        for ($block = 0; $block < $frameBytes / 16; $block++) {
            $frames .= md5($frame . ':' . $block, true);
        }
    }

    return $frames;
}

/**
 * A CHD image of the frames in uncompressed hunks, with the metadata.
 *
 * @param  list<array{string, string}>  $metadata
 */
function cdChd(string $directory, string $frames, array $metadata, int $framesPerHunk = 2, int $unitBytes = CD_FRAME_BYTES): string
{
    $hunkBytes = $framesPerHunk * $unitBytes;
    $hunks = array_map(
        fn (string $hunk): array => [ChdMapEntry::UNCOMPRESSED, $hunk, Crc16::compute($hunk)],
        mb_str_split(mb_str_pad($frames, (int) ceil(mb_strlen($frames, '8bit') / $hunkBytes) * $hunkBytes, "\0", STR_PAD_RIGHT, '8bit'), $hunkBytes, '8bit'),
    );

    return chdOnDisk($directory, syntheticChd($hunks, metadata: $metadata, hunkBytes: $hunkBytes, unitBytes: $unitBytes));
}

/**
 * @return array{string, string}
 */
function cht2(int $number, string $type, int $frames, int $pregap = 0): array
{
    return ['CHT2', 'TRACK:' . $number . ' TYPE:' . $type . ' SUBTYPE:NONE FRAMES:' . $frames . ' PREGAP:' . $pregap . ' PGTYPE:MODE1 PGSUB:NONE POSTGAP:0' . "\0"];
}

/**
 * The bytes of the frames from the offset into the frame on.
 */
function framesAt(string $frames, int $frame, int $offset, int $length, int $frameBytes = CD_FRAME_BYTES): string
{
    return mb_substr($frames, $frame * $frameBytes + $offset, $length, '8bit');
}

/**
 * The ISO 9660 volume descriptor the fixture generator writes in sector 16.
 */
function fixtureVolumeDescriptor(string $label): string
{
    return "\x01CD001\x01" . mb_substr(str_repeat($label, 2041), 0, 2041, '8bit');
}

it('finds the tracks of a cd image', function (int|SpecialTrack $track, int $firstSector): void {
    expect(ChdTrack::open(chdFixturePath('audio-data-audio'), $track)->firstSector())->toBe($firstSector);
})->with([
    'first audio track' => [1, 0],
    'data track' => [2, 24],
    'last audio track' => [3, 72],
    'first data track' => [SpecialTrack::FirstData, 24],
    'last track' => [SpecialTrack::Last, 72],
    'first track of the second session, as track 2' => [SpecialTrack::FirstOfSecondSession, 24],
]);

it('reads the user data of raw mode 1 sectors', function (): void {
    $track = ChdTrack::open(chdFixturePath('audio-data-audio'), SpecialTrack::FirstData);

    expect($track->readSector(24 + 16, 2048))->toBe(fixtureVolumeDescriptor('track 2'))
        ->and($track->readSector(24, 19))->toBe('track 2 sector 00: ');
});

it('reads the sectors of cd images compressed with lzma and zlib, and of mode 2 xa form 1', function (string $name, string $label): void {
    $track = ChdTrack::open(chdFixturePath($name), 1);
    $sectorStarts = fn (string $sectors): array => array_map(fn (string $sector): string => mb_substr($sector, 0, 17, '8bit'), mb_str_split($sectors, 2048, '8bit'));

    expect($sectorStarts($track->readSector(1, 3 * 2048)))->toBe([$label . ' sector 01: ', $label . ' sector 02: ', $label . ' sector 03: '])
        ->and($sectorStarts($track->readSector(15, 2 * 2048))[0])->toBe($label . ' sector 15: ');
})->with([
    'lzma' => ['mode1-cdlz', 'mode1'],
    'zlib' => ['mode1-cdzl', 'mode1'],
    'mode 2 in hunks of 2 frames' => ['mode2', 'mode2'],
]);

it('stops reading at the end of the image', function (): void {
    $track = ChdTrack::open(chdFixturePath('mode1-cdzl'), 1);

    expect(mb_strlen($track->readSector(15, 2 * 2048), '8bit'))->toBe(2048)
        ->and($track->readSector(16, 2048))->toBe('');
});

it('tells the layout of mode 2 sectors from their volume descriptor', function (): void {
    expect(ChdTrack::open(chdFixturePath('mode2'), 1)->readSector(16, 2048))->toBe(fixtureVolumeDescriptor('mode2'));
});

it('reads the sectors of audio tracks, which are compressed with flac', function (): void {
    $track = ChdTrack::open(new SplFileInfo(chdFixturePath('audio-data-audio')), 1);

    expect(md5($track->readSector(0, 2352)))->toBe('5fa24e92f3e3a9a9abe3f364ba2f74d6')
        ->and(md5($track->readSector(23, 2352)))->toBe('78e5c41f914eef4c24a5adcb716b5762');
});

it('opens tracks of an image already open', function (): void {
    $chd = ChdFile::open(chdFixturePath('audio-data-audio'));

    expect(ChdTrack::open($chd, 2)->readSector(24, 19))->toBe('track 2 sector 00: ');
});

it('reads sectors within the track and the one after its last frame', function (): void {
    $frames = distinctCdFrames(8);
    $track = ChdTrack::open(cdChd($this->directory, $frames, [cht2(1, 'AUDIO', 2), cht2(2, 'MODE1_RAW', 3)]), 2);

    expect($track->firstSector())->toBe(2)
        ->and($track->readSector(2, 2048))->toBe(framesAt($frames, 4, 16, 2048))
        ->and($track->readSector(5, 2048))->toBe(framesAt($frames, 7, 16, 2048))
        ->and($track->readSector(1, 2048))->toBe('')
        ->and($track->readSector(-1, 2048))->toBe('')
        ->and($track->readSector(6, 2048))->toBe('');
});

it('reads parts of sectors and carries on into the next sectors and hunks', function (): void {
    $frames = distinctCdFrames(4);
    $track = ChdTrack::open(cdChd($this->directory, $frames, [cht2(1, 'MODE1_RAW', 4)]), 1);

    expect($track->readSector(0, 100))->toBe(framesAt($frames, 0, 16, 100))
        ->and($track->readSector(1, 2048 + 10))->toBe(framesAt($frames, 1, 16, 2048) . framesAt($frames, 2, 16, 10))
        ->and($track->readSector(0, 0))->toBe('');
});

it('places tracks after the pregap and the padding of the tracks before to 4 frames', function (): void {
    $frames = distinctCdFrames(14);
    $track = ChdTrack::open(cdChd($this->directory, $frames, [cht2(1, 'MODE1_RAW', 5), cht2(2, 'MODE1_RAW', 3, pregap: 2)]), 2);

    expect($track->firstSector())->toBe(5)
        ->and($track->readSector(5, 2048))->toBe(framesAt($frames, 10, 16, 2048));
});

it('reads old cd and gd-rom track metadata', function (array $metadata, int $firstFrame): void {
    $frames = distinctCdFrames(12);
    $track = ChdTrack::open(cdChd($this->directory, $frames, $metadata), 2);

    expect($track->firstSector())->toBe(2)
        ->and($track->readSector(2, 2048))->toBe(framesAt($frames, $firstFrame, 0, 2048));
})->with([
    'old cd metadata' => [[
        ['CHTR', 'TRACK:1 TYPE:MODE1 SUBTYPE:NONE FRAMES:2'],
        ['CHTR', 'TRACK:2 TYPE:MODE1 SUBTYPE:NONE FRAMES:4'],
    ], 4],
    'gd-rom metadata' => [[
        ['CHGD', 'TRACK:1 TYPE:MODE1 SUBTYPE:NONE FRAMES:2 PAD:0 PREGAP:0 PGTYPE:MODE1 PGSUB:NONE POSTGAP:0'],
        ['CHGD', 'TRACK:2 TYPE:MODE1 SUBTYPE:NONE FRAMES:4 PAD:0 PREGAP:3 PGTYPE:MODE1 PGSUB:NONE POSTGAP:0'],
    ], 7],
]);

it('reads the single track of a dvd as 2048-byte units', function (): void {
    $units = distinctCdFrames(6, 2048);
    $path = cdChd($this->directory, $units, [['DVD ', "\0"]], unitBytes: 2048);

    foreach ([1, SpecialTrack::FirstData, SpecialTrack::Largest] as $number) {
        $track = ChdTrack::open($path, $number);

        expect($track->firstSector())->toBe(0)
            ->and($track->readSector(1, 3 * 2048))->toBe(framesAt($units, 1, 0, 3 * 2048, 2048));
    }

    expect(fn (): ChdTrack => ChdTrack::open($path, 2))->toThrow(RuntimeException::class, 'has no track 2');
});

it('pairs the type and frames of the largest data track with the position of the last track, like rahasher', function (array $metadata, int $firstSector, int $headerBytes, int $dataBytes, int $frameCount): void {
    $frames = distinctCdFrames(48);
    $track = ChdTrack::open(cdChd($this->directory, $frames, $metadata), SpecialTrack::Largest);
    $lastSector = $firstSector + $frameCount;

    expect($track->firstSector())->toBe($firstSector)
        ->and(array_map(fn (int $sector): string => $track->readSector($sector, $dataBytes), [$firstSector, $lastSector, $lastSector + 1]))
        ->toBe([framesAt($frames, $firstSector, $headerBytes, $dataBytes), framesAt($frames, $lastSector, $headerBytes, $dataBytes), '']);
})->with([
    'the first of the largest data tracks' => [[cht2(1, 'MODE1', 8), cht2(2, 'MODE1_RAW', 12), cht2(3, 'MODE1', 12), cht2(4, 'AUDIO', 4)], 32, 16, 2048, 12],
    'the first track when all are audio' => [[cht2(1, 'AUDIO', 4), cht2(2, 'AUDIO', 16)], 4, 0, 2352, 4],
]);

it('has no track to open', function (string|array $image, int|SpecialTrack $track, string $message): void {
    $path = is_array($image) ? cdChd($this->directory, distinctCdFrames(4), $image) : chdFixturePath($image);

    expect(fn (): ChdTrack => ChdTrack::open($path, $track))->toThrow(RuntimeException::class, $message);
})->with([
    'missing track number' => ['audio-data-audio', 4, 'has no track 4'],
    'track 0' => ['audio-data-audio', 0, 'has no track 0'],
    'no data track' => [[cht2(1, 'AUDIO', 4)], SpecialTrack::FirstData, 'has no FirstData track'],
    'no second track for the second session' => [[cht2(1, 'MODE1', 4)], SpecialTrack::FirstOfSecondSession, 'has no FirstOfSecondSession track'],
    'image without cd metadata' => ['raw-none', 1, 'has no track 1'],
    'last track of an image without cd metadata' => ['raw-none', SpecialTrack::Last, 'has no Last track'],
    'largest track of an image without cd metadata' => ['raw-none', SpecialTrack::Largest, 'has no Largest track'],
]);

it('rejects tracks it cannot read', function (Closure $image, string $message): void {
    $path = $image($this->directory);

    expect(fn (): ChdTrack => ChdTrack::open($path, 1))->toThrow(RuntimeException::class, $message);
})->with([
    'unreadable metadata' => [fn (string $directory): string => cdChd($directory, distinctCdFrames(4), [['CHT2', 'TRACK:1 TYPE:MODE1 FRAMES:4']]), 'track metadata "TRACK:1 TYPE:MODE1 FRAMES:4" cannot be read'],
    'units larger than hunks' => [fn (string $directory): string => chdOnDisk($directory, syntheticChd([[0, '', 0]], metadata: [cht2(1, 'MODE1', 4)], hunkBytes: 300, unitBytes: 600)), 'hunks of 300 bytes do not hold whole units'],
    'too few frames for a volume descriptor' => [fn (string $directory): string => cdChd($directory, distinctCdFrames(16), [cht2(1, 'MODE2_RAW', 15)]), 'too short to tell the layout of its sectors'],
]);

it('reads xa form 2 sectors after their subheader, into the next sector', function (): void {
    $frames = distinctCdFrames(20);
    $volumeDescriptor = "\x00\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\x00\x00\x02\x16\x02\x00\x00\x28\x00\x00\x00\x28\x00\x01CD001";
    $frames = framesAt($frames, 0, 0, 16 * CD_FRAME_BYTES) . $volumeDescriptor . mb_substr($frames, 16 * CD_FRAME_BYTES + 32, null, '8bit');
    $track = ChdTrack::open(cdChd($this->directory, $frames, [cht2(1, 'MODE2_RAW', 16)]), 1);

    expect($track->readSector(16, 3000))->toBe(framesAt($frames, 16, 24, 2324) . framesAt($frames, 17, 24, 3000 - 2324));
});
