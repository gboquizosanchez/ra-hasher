<?php

declare(strict_types=1);

use Boquizo\RaHasher\Disc\Nintendo\NintendoDisc;
use Boquizo\RaHasher\Disc\Nintendo\WiiDiscHash;

function wiiDiscMd5(NintendoDisc $disc): string
{
    $context = hash_init('md5');
    WiiDiscHash::append($context, $disc);

    return hash_final($context);
}

function wiiDiscInMemory(string $iso): NintendoDisc
{
    return sparseNintendoDisc([0 => $iso], mb_strlen($iso, '8bit'));
}

/**
 * The start of an encrypted Wii disc with a single game partition at
 * 0x50000, its TMD of 0x100 bytes right after its header and its data at the
 * given offset of the disc.
 */
function singlePartitionWiiDisc(int $dataOffset, int $dataSize): string
{
    $iso = wiiDiscStart(0x58000, true);
    $iso = substr_replace($iso, pack('NN', 1, 0x40020 >> 2), 0x40000, 8);
    $iso = substr_replace($iso, pack('NN', 0x50000 >> 2, 0), 0x40020, 8);
    $iso = wiiPartitionHeader($iso, 0x50000, 0x100, WII_TMD_OFFSET, $dataOffset, $dataSize);

    return substr_replace($iso, noiseBytes('single tmd', 0x100), 0x50000 + WII_TMD_OFFSET, 0x100);
}

it('hashes the synthetic discs like rahasher', function (): void {
    expect(syntheticWiiHash(syntheticWiiDisc()))->toBe(SYNTHETIC_WII_HASH)
        ->and(syntheticDecryptedWiiHash(syntheticDecryptedWiiDisc()))->toBe(SYNTHETIC_DECRYPTED_WII_HASH);
});

it('hashes the tmd and the clusters of every partition but the update one', function (): void {
    expect(wiiDiscMd5(wiiDiscInMemory(syntheticWiiDisc())))->toBe(SYNTHETIC_WII_HASH);
});

it('hashes the header, apploader and dol of the partitions of a decrypted disc', function (): void {
    expect(wiiDiscMd5(wiiDiscInMemory(syntheticDecryptedWiiDisc())))->toBe(SYNTHETIC_DECRYPTED_WII_HASH);
});

it('hashes at most 1024 clusters of a partition', function (): void {
    $dataOffset = 0x100000;
    $clusterData = static fn (int $cluster): array => [$dataOffset + $cluster * 0x8000 + 0x400 => noiseBytes('cluster' . $cluster, 0x7C00)];
    $segments = $clusterData(1023) + $clusterData(1024);
    $discWith = static fn (int $clusters): NintendoDisc => sparseNintendoDisc(
        [0 => singlePartitionWiiDisc($dataOffset, $clusters * 0x8000)] + $segments,
        $dataOffset + 1100 * 0x8000,
    );

    expect(wiiDiscMd5($discWith(1100)))->toBe(wiiDiscMd5($discWith(1024)))
        ->not->toBe(wiiDiscMd5($discWith(1023)));
});

it('throws on an empty or too big partition table', function (array $groups, string $message): void {
    $iso = substr_replace(singlePartitionWiiDisc(0x8000, 0), pack('N8', ...$groups), 0x40000, 32);

    expect(fn (): string => wiiDiscMd5(wiiDiscInMemory($iso)))->toThrow(RuntimeException::class, $message);
})->with([
    'no partitions' => [[0, 0x40020 >> 2, 0, 0, 0, 0, 0, 0], 'The Wii disc has no partitions.'],
    '17 partitions' => [[16, 0x40020 >> 2, 0, 0, 1, 0x40020 >> 2, 0, 0], 'The Wii disc has too many partitions.'],
]);

it('reads offsets from 0x80000000 words on 16 gib before the intended byte', function (): void {
    $iso = substr_replace(singlePartitionWiiDisc(0x8000, 0), pack('N', 0xFFFFFFFF), 0x50000 + 0x2A8, 4);
    $tmd = mb_substr($iso, 0x50000 - 4, 0x100, '8bit');

    expect(wiiDiscMd5(wiiDiscInMemory($iso)))
        ->toBe(md5(mb_substr($iso, 0, 0x80, '8bit') . mb_substr($iso, 0x4E000, 4, '8bit') . $tmd));
});

it('throws on data offsets before the start of the disc', function (): void {
    $iso = substr_replace(singlePartitionWiiDisc(0x8000, 0x8000), pack('N', 0x80000000), 0x50000 + 0x2B8, 4);

    expect(fn (): string => wiiDiscMd5(wiiDiscInMemory($iso)))->toThrow(RuntimeException::class);
});

it('truncates the data offset of a decrypted partition to 32 bits', function (): void {
    $iso = substr_replace(syntheticDecryptedWiiDisc(), pack('N', 0x40008000), 0x50000 + 0x2B8, 4);

    expect(wiiDiscMd5(wiiDiscInMemory($iso)))->toBe(SYNTHETIC_DECRYPTED_WII_HASH);
});
