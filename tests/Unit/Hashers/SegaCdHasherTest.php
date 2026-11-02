<?php

declare(strict_types=1);

use Boquizo\RaHasher\Hashers\SegaCdHasher;

/** The first sector of a disc: the system identifier, then filler. */
function segaCdSector(string $identifier): string
{
    return mb_str_pad($identifier, 2048, 'h', STR_PAD_RIGHT, '8bit');
}

it('hashes the first 512 bytes of a Sega CD or Saturn disc', function (string $identifier): void {
    $sector = segaCdSector($identifier);

    expect((new SegaCdHasher())->hashTrack(fakeDiscTrack([0 => $sector])))->toBe(md5(mb_substr($sector, 0, 512, '8bit')));
})->with([
    'sega cd' => 'SEGADISCSYSTEM  ',
    'saturn' => 'SEGA SEGASATURN ',
]);

it('finds nothing to hash on a disc of another system', function (string $identifier): void {
    expect((new SegaCdHasher())->hashTrack(fakeDiscTrack([0 => segaCdSector($identifier)])))->toBeNull();
})->with([
    'unknown identifier' => 'NOT A SEGA DISC.',
    'lowercase identifier' => 'segadiscsystem  ',
    'missing trailing space' => 'SEGADISCSYSTEM h',
    'empty' => '',
]);

it('finds nothing to hash when the first sector cannot be read in full', function (): void {
    expect((new SegaCdHasher())->hashTrack(fakeDiscTrack([])))->toBeNull()
        ->and((new SegaCdHasher())->hashTrack(fakeDiscTrack([0 => 'SEGADISCSYSTEM  ' . str_repeat('h', 100)])))->toBeNull();
});
