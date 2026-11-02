<?php

declare(strict_types=1);

use Boquizo\RaHasher\Disc\DiscTrack;
use Boquizo\RaHasher\Hashers\ThreeDoHasher;

/** A volume header: the Opera identifier, the block size (at 0x4D) and the root directory block (at 0x65). */
function threeDoHeader(int $blockSize = 2048, int $rootBlock = 5): string
{
    $header = mb_str_pad("\x01ZZZZZ\x01", 0x4D, 'v', STR_PAD_RIGHT, '8bit') . mb_substr(pack('N', $blockSize), 1, null, '8bit');
    $header = mb_str_pad($header, 0x65, 'v', STR_PAD_RIGHT, '8bit') . mb_substr(pack('N', $rootBlock), 1, null, '8bit');

    return mb_str_pad($header, 2048, 'v', STR_PAD_RIGHT, '8bit');
}

/** A directory entry: type (at 3), block size (0x0D), size (0x11), name (0x20), extra words (0x43) and block (0x45). */
function threeDoEntry(string $name, int $size, int $block, int $type = 2, int $blockSize = 2048, int $extraWords = 0): string
{
    $entry = mb_str_pad("\0\0\0" . chr($type), 0x0D, "\0", STR_PAD_RIGHT, '8bit') . mb_substr(pack('N', $blockSize), 1, null, '8bit');
    $entry = mb_str_pad($entry, 0x11, "\0", STR_PAD_RIGHT, '8bit') . mb_substr(pack('N', $size), 1, null, '8bit');
    $entry = mb_str_pad($entry, 0x20, "\0", STR_PAD_RIGHT, '8bit') . mb_str_pad($name, 0x20, "\0", STR_PAD_RIGHT, '8bit');
    $entry = mb_str_pad($entry, 0x43, "\0", STR_PAD_RIGHT, '8bit') . chr($extraWords);
    $entry = mb_str_pad($entry, 0x45, "\0", STR_PAD_RIGHT, '8bit') . mb_substr(pack('N', $block), 1, null, '8bit');

    return mb_str_pad($entry, 0x48 + $extraWords * 4, "\0", STR_PAD_RIGHT, '8bit');
}

/**
 * A directory block: the next block (at 2), where the entries stop (0x0D) and
 * begin (0x12), then the entries from 0x14.
 *
 * @param  list<string>  $entries
 */
function threeDoDirectory(array $entries, int $next = 0xFFFF, ?int $stop = null): string
{
    $body = implode('', $entries);
    $stop ??= 0x14 + mb_strlen($body, '8bit');
    $block = mb_str_pad("\0\0" . pack('n', $next), 0x0D, "\0", STR_PAD_RIGHT, '8bit') . mb_substr(pack('N', $stop), 1, null, '8bit');
    $block = mb_str_pad($block, 0x12, "\0", STR_PAD_RIGHT, '8bit') . pack('n', 0x14) . $body;

    return mb_str_pad($block, 2048, "\0", STR_PAD_RIGHT, '8bit');
}

/** A disc with the root directory at sector 5 and the LaunchMe file at sector 8. */
function threeDoDisc(string $launchMe, ?string $directory = null): DiscTrack
{
    $sectors = [0 => threeDoHeader(), 5 => $directory ?? threeDoDirectory([threeDoEntry('LaunchMe', mb_strlen($launchMe, '8bit'), 8)])];

    foreach (mb_str_split($launchMe, 2048, '8bit') as $index => $chunk) {
        $sectors[8 + $index] = mb_str_pad($chunk, 2048, "\0", STR_PAD_RIGHT, '8bit');
    }

    return fakeDiscTrack($sectors);
}

it('hashes the volume header and the LaunchMe file', function (int $size): void {
    $content = mb_substr(str_repeat('0123456789', 700), 0, $size, '8bit');

    expect((new ThreeDoHasher())->hashTrack(threeDoDisc($content)))->toBe(md5(mb_substr(threeDoHeader(), 0, 132, '8bit') . $content));
})->with([
    'less than a sector' => 100,
    'one sector' => 2048,
    'a sector and a byte' => 2049,
    'several sectors' => 5000,
]);

it('finds LaunchMe whatever its case, among other entries', function (): void {
    $directory = threeDoDirectory([
        threeDoEntry('LaunchMe', 4, 9, type: 7),
        threeDoEntry('Other', 4, 9),
        threeDoEntry('LaunchMeToo', 4, 9, extraWords: 3),
        threeDoEntry('launchme', 6, 8, extraWords: 2),
    ]);

    expect((new ThreeDoHasher())->hashTrack(threeDoDisc('launch', $directory)))->toBe(md5(mb_substr(threeDoHeader(), 0, 132, '8bit') . 'launch'));
});

it('locates the blocks with the block size of the entry', function (): void {
    $directory = threeDoDirectory([threeDoEntry('LaunchMe', 6, 32, blockSize: 512)]);

    expect((new ThreeDoHasher())->hashTrack(threeDoDisc('launch', $directory)))->toBe(md5(mb_substr(threeDoHeader(), 0, 132, '8bit') . 'launch'));
});

it('follows the directory to its next block to find LaunchMe', function (): void {
    $sectors = [
        0 => threeDoHeader(),
        5 => threeDoDirectory([threeDoEntry('Other', 4, 9)], next: 1),
        6 => threeDoDirectory([threeDoEntry('LaunchMe', 6, 8)]),
        8 => mb_str_pad('launch', 2048, "\0", STR_PAD_RIGHT, '8bit'),
    ];

    expect((new ThreeDoHasher())->hashTrack(fakeDiscTrack($sectors)))->toBe(md5(mb_substr(threeDoHeader(), 0, 132, '8bit') . 'launch'));
});

it('finds nothing to hash on a disc of another system', function (string $header): void {
    expect((new ThreeDoHasher())->hashTrack(fakeDiscTrack([0 => $header])))->toBeNull();
})->with([
    'unknown identifier' => [mb_str_pad('NOT A 3DO DISC', 2048, "\0", STR_PAD_RIGHT, '8bit')],
    'identifier cut short' => ["\x01ZZZZZ"],
    'header cut short' => [mb_substr(threeDoHeader(), 0, 131, '8bit')],
    'empty' => [''],
]);

it('finds nothing to hash when LaunchMe cannot be found', function (Closure $directory): void {
    expect((new ThreeDoHasher())->hashTrack(threeDoDisc('launch', $directory())))->toBeNull();
})->with([
    'no entries' => fn () => threeDoDirectory([]),
    'no LaunchMe' => fn () => threeDoDirectory([threeDoEntry('Other', 6, 8)]),
    'LaunchMe is a directory' => fn () => threeDoDirectory([threeDoEntry('LaunchMe', 6, 8, type: 1)]),
    'LaunchMe is empty' => fn () => threeDoDirectory([threeDoEntry('LaunchMe', 0, 8)]),
    'the name has more characters' => fn () => threeDoDirectory([threeDoEntry('LaunchMe2', 6, 8)]),
    'the directory points to itself' => fn () => threeDoDirectory([threeDoEntry('Other', 6, 8)], next: 0),
    'entries run past the end of the block' => fn () => threeDoDirectory([threeDoEntry('Other', 6, 8)], stop: 0xFFFFFF),
]);

it('finds nothing to hash when the disc cannot be read', function (int $firstMissing): void {
    $directory = threeDoDirectory([threeDoEntry('LaunchMe', 5000, 8)]);

    expect((new ThreeDoHasher())->hashTrack(trackFrom(threeDoDisc(str_repeat('l', 5000), $directory), $firstMissing)))->toBeNull();
})->with([
    'the directory' => 5,
    'the first sector of the file' => 8,
    'a later sector of the file' => 9,
    'the last sector of the file' => 10,
]);
