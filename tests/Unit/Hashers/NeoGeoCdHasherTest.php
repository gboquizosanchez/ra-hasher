<?php

declare(strict_types=1);

use Boquizo\RaHasher\Disc\Iso9660;
use Boquizo\RaHasher\Hashers\NeoGeoCdHasher;

it('hashes the contents of the programs IPL.TXT lists, in order', function (string $list): void {
    $track = isoDisc(['IPL.TXT;1' => $list, 'FIRST.PRG;1' => str_repeat('a', 3000), 'SECOND.PRG;1' => str_repeat('b', 100)]);

    expect((new NeoGeoCdHasher())->hashTrack($track))->toBe(md5(str_repeat('a', 3000) . str_repeat('b', 100)));
})->with([
    'unix lines' => "FIRST.PRG,0,0\nSECOND.PRG,0,0\n",
    'dos lines' => "FIRST.PRG,0,0\r\nSECOND.PRG,0,0\r\n",
    'lowercase extension' => "FIRST.prg,0,0\nSECOND.Prg,0,0\n",
    'other lines between' => "DATA.TXT,0,0\nFIRST.PRG,0,0\nSOUND.PCM,0,0\nSECOND.PRG,0,0\n",
    'no final newline' => "FIRST.PRG,0,0\nSECOND.PRG,0,0",
    'text after a null byte is ignored' => "FIRST.PRG,0,0\nSECOND.PRG,0,0\n\0THIRD.PRG,0,0\n",
    'text after a control z is ignored' => "FIRST.PRG,0,0\nSECOND.PRG,0,0\n\x1aTHIRD.PRG,0,0\n",
]);

it('hashes nothing but still succeeds when no program is listed', function (string $list): void {
    expect((new NeoGeoCdHasher())->hashTrack(isoDisc(['IPL.TXT;1' => $list])))->toBe(md5(''));
})->with([
    'no program' => "DATA.TXT,0,0\nSOUND.PCM,0,0\n",
    'one line without newline' => 'DATA.TXT,0,0',
    'program extension without a dot first' => "PRG,0,0\n",
    'empty line' => "\n",
    'dot at the end' => 'DATA.',
    'null byte first' => "\0FIRST.PRG,0,0\n",
]);

it('skips the line after a program when its name is followed by a newline', function (): void {
    $track = isoDisc(['IPL.TXT;1' => "FIRST.PRG\nSECOND.PRG\nTHIRD.PRG,0,0\n", 'FIRST.PRG;1' => 'first', 'SECOND.PRG;1' => 'second', 'THIRD.PRG;1' => 'third']);

    expect((new NeoGeoCdHasher())->hashTrack($track))->toBe(md5('firstthird'));
});

it('reads the list up to its first 1023 bytes', function (): void {
    $list = str_repeat("DATA.TXT,0,0\n", 70) . "FIRST.PRG,0,0\n" . str_repeat(' ', 100) . "\nSECOND.PRG,0,0\n";
    $track = isoDisc(['IPL.TXT;1' => $list, 'FIRST.PRG;1' => 'first', 'SECOND.PRG;1' => 'second']);

    expect((new NeoGeoCdHasher())->hashTrack($track))->toBe(md5('first'));
});

it('hashes a program whose extension ends at the byte 1023 of the list and not one that ends at the byte 1024', function (int $nameStart, string $expected): void {
    $list = 'D.' . str_repeat('x', $nameStart - 3) . "\nA.PRG";
    $track = isoDisc(['IPL.TXT;1' => $list, 'A.PRG;1' => 'program']);

    expect((new NeoGeoCdHasher())->hashTrack($track))->toBe($expected);
})->with([
    'the extension ends at the byte 1023' => [1018, md5('program')],
    'the extension ends at the byte 1024' => [1019, md5('')],
]);

it('stops at a program name cut by the end of the list', function (): void {
    $name = str_repeat('x', 1019) . '.PRG';
    $track = isoDisc(['IPL.TXT;1' => $name . 'xx', $name => 'program']);

    expect((new NeoGeoCdHasher())->hashTrack($track))->toBeNull();
});

it('finds programs in the directories of the disc', function (): void {
    $track = isoDisc(['IPL.TXT;1' => "SUB\\FIRST.PRG,0,0\n", 'SUB' => ['FIRST.PRG;1' => 'first']]);

    expect((new NeoGeoCdHasher())->hashTrack($track))->toBe(md5('first'));
});

it('finds nothing to hash without a readable list', function (): void {
    $track = isoDisc(['IPL.TXT;1' => "FIRST.PRG,0,0\n", 'FIRST.PRG;1' => 'first']);
    $listSector = Iso9660::findFile($track, 'IPL.TXT')['sector'];

    expect((new NeoGeoCdHasher())->hashTrack(isoDisc(['FIRST.PRG;1' => 'first'])))->toBeNull()
        ->and((new NeoGeoCdHasher())->hashTrack(trackFrom($track, $listSector)))->toBeNull();
});

it('finds nothing to hash when a listed program is missing or cannot be read', function (): void {
    $list = "FIRST.PRG,0,0\nSECOND.PRG,0,0\n";
    $track = isoDisc(['IPL.TXT;1' => $list, 'FIRST.PRG;1' => 'first', 'SECOND.PRG;1' => 'second']);
    $secondSector = Iso9660::findFile($track, 'SECOND.PRG')['sector'];

    expect((new NeoGeoCdHasher())->hashTrack(isoDisc(['IPL.TXT;1' => $list, 'FIRST.PRG;1' => 'first'])))->toBeNull()
        ->and((new NeoGeoCdHasher())->hashTrack(trackFrom($track, $secondSector)))->toBeNull();
});
