<?php

declare(strict_types=1);

use Boquizo\RaHasher\Disc\Nintendo\NintendoDisc;
use Boquizo\RaHasher\Disc\Nintendo\WiiWareHash;
use Boquizo\RaHasher\GameHasher;

function wiiWareMd5(NintendoDisc $file): string
{
    $context = hash_init('md5');
    WiiWareHash::append($context, $file);

    return hash_final($context);
}

/** Feeds the hash with the bytes followed by zeros up to the size, 1 MiB at a time. */
function zeroPaddedMd5Update(HashContext $context, string $bytes, int $size): void
{
    hash_update($context, $bytes);

    for ($done = mb_strlen($bytes, '8bit'); $done < $size; $done += 1024 * 1024) {
        hash_update($context, str_repeat("\0", min(1024 * 1024, $size - $done)));
    }
}

it('hashes the synthetic wad like rahasher', function (): void {
    expect(syntheticWadHash(syntheticWad()))->toBe(SYNTHETIC_WAD_HASH);
});

it('hashes the tmd and every content up to its aes block', function (): void {
    $wad = syntheticWad();

    expect(wiiWareMd5(sparseNintendoDisc([0 => $wad], mb_strlen($wad, '8bit'))))->toBe(SYNTHETIC_WAD_HASH);
});

it('hashes 64 mib of a content bigger than 4 gib and skips that much', function (): void {
    $wad = wadHeaderAndTmd([[1, 0x10], [0, 0x20]]);
    $first = noiseBytes('huge content', 0x100);
    $second = noiseBytes('next content', 0x20);
    $file = sparseNintendoDisc([0 => $wad, 0xF80 => $first, 0x4000F80 => $second], 0x4000FA0);

    $expected = hash_init('md5');
    hash_update($expected, mb_substr($wad, WAD_TMD_START, 0x240, '8bit'));
    zeroPaddedMd5Update($expected, $first, GameHasher::MAX_BUFFER_SIZE);
    hash_update($expected, $second);

    expect(mb_strlen($wad, '8bit'))->toBe(0xF80)
        ->and(wiiWareMd5($file))->toBe(hash_final($expected));
});

it('hashes at most 64 mib of tmd', function (): void {
    $wad = substr_replace(wadHeaderAndTmd([[0, 0x20]]), pack('N', 0x5000000), 0x14, 4);
    $tmd = mb_substr($wad, WAD_TMD_START, null, '8bit');
    $content = noiseBytes('content after the tmd', 0x20);
    $contentStart = WAD_TMD_START + GameHasher::MAX_BUFFER_SIZE;
    $file = sparseNintendoDisc([0 => $wad, $contentStart => $content], $contentStart + 0x20);

    $expected = hash_init('md5');
    zeroPaddedMd5Update($expected, $tmd, GameHasher::MAX_BUFFER_SIZE);
    hash_update($expected, $content);

    expect(wiiWareMd5($file))->toBe(hash_final($expected));
});

it('wraps content sizes around 32 bits', function (): void {
    $wad = wadHeaderAndTmd([[0, 0xFFFFFFF5], [0, 0x20]]);
    $content = noiseBytes('content after the wrapped one', 0x20);
    $file = sparseNintendoDisc([0 => $wad . $content], mb_strlen($wad, '8bit') + 0x20);

    expect(wiiWareMd5($file))->toBe(md5(mb_substr($wad, WAD_TMD_START, null, '8bit') . $content));
});
