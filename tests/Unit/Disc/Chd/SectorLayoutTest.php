<?php

declare(strict_types=1);

use Boquizo\RaHasher\Disc\Chd\SectorLayout;

const CD_SYNC = "\x00\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\x00";

/**
 * The first 32 bytes of a raw sector: the sync pattern, the address and the
 * mode, followed by the rest of the sector.
 */
function rawSectorSample(int $mode, string $rest): string
{
    return mb_str_pad(CD_SYNC . "\x00\x02\x16" . chr($mode) . $rest, SectorLayout::SAMPLE_BYTES, "\0", STR_PAD_RIGHT, '8bit');
}

it('tells the layout of raw mode 1, mode 1 and audio tracks by their type', function (string $type, int $headerBytes, int $dataBytes): void {
    $layout = SectorLayout::detect($type, fn (): string => throw new LogicException('The sample must not be read.'));

    expect([$layout->headerBytes, $layout->dataBytes])->toBe([$headerBytes, $dataBytes]);
})->with([
    'raw mode 1' => ['MODE1_RAW', 16, 2048],
    'mode 1' => ['MODE1', 0, 2048],
    'audio' => ['AUDIO', 0, 2352],
]);

it('tells the layout of other tracks from the start of sector 16', function (string $type, string $sample, int $headerBytes, int $dataBytes): void {
    $layout = SectorLayout::detect($type, fn (): string => $sample);

    expect([$layout->headerBytes, $layout->dataBytes])->toBe([$headerBytes, $dataBytes]);
})->with([
    'xa form 1' => ['MODE2_RAW', rawSectorSample(2, "\x00\x00\x08\x00\x00\x00\x08\x00\x01CD001"), 24, 2048],
    'xa form 2' => ['MODE2_RAW', rawSectorSample(2, "\x00\x00\x28\x00\x00\x00\x28\x00\x01CD001"), 24, 2324],
    'mode 1 header' => ['MODE2_RAW', rawSectorSample(1, "\x01CD001"), 16, 2048],
    'mode 2 header' => ['MODE2_FORM_MIX', rawSectorSample(2, "\x01CD001"), 16, 2336],
    'mode 0 header' => ['MODE2_FORM1', rawSectorSample(0, "\x01CD001"), 16, 2336],
    'no header' => ['MODE2', mb_str_pad("\x01CD001\x01", 32, "\0", STR_PAD_RIGHT, '8bit'), 0, 2048],
    'mode 1 sync without a volume descriptor' => ['MODE2_RAW', rawSectorSample(0x61, ''), 16, 2048],
    'mode 2 sync without a volume descriptor' => ['MODE2_RAW', rawSectorSample(2, ''), 16, 2336],
    'neither' => ['MODE2_RAW', str_repeat("\xFF", 32), 0, 2048],
    'longer sample' => ['MODE2_RAW', rawSectorSample(1, "\x01CD001") . str_repeat("\0", 2000), 16, 2048],
]);

it('needs the 32 bytes of the sample', function (): void {
    expect(fn (): SectorLayout => SectorLayout::detect('MODE2_RAW', fn (): string => mb_substr(rawSectorSample(2, ''), 0, 31, '8bit')))
        ->toThrow(RuntimeException::class, 'too short to tell the layout of its sectors');
});
