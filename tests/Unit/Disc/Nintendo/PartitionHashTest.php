<?php

declare(strict_types=1);

use Boquizo\RaHasher\Disc\Nintendo\NintendoDisc;
use Boquizo\RaHasher\Disc\Nintendo\PartitionHash;

/**
 * A disc held in memory that throws, like the real ones, on a range past its end.
 */
function inMemoryNintendoDisc(string $bytes): NintendoDisc
{
    return new readonly class($bytes) implements NintendoDisc
    {
        public function __construct(private string $bytes) {}

        public function read(int $offset, int $length): string
        {
            if ($offset + $length > $this->size()) {
                throw new RuntimeException('Past the end of the disc.');
            }

            return mb_substr($this->bytes, $offset, $length, '8bit');
        }

        public function size(): int
        {
            return mb_strlen($this->bytes, '8bit');
        }
    };
}

/**
 * Header bytes whose apploader declares the body and trailer sizes, with the
 * DOL offset field pointing to $dolOffset.
 */
function nintendoPartitionHeader(int $body, int $trailer, int $dolOffset, int $bytes): string
{
    $header = noiseBytes('partition', $bytes);
    $header = substr_replace($header, pack('N', $dolOffset), 0x420, 4);

    return substr_replace($header, pack('NN', $body, $trailer), 0x2454, 8);
}

function partitionMd5(NintendoDisc $disc, int $partitionOffset = 0, int $shift = 0): string
{
    $context = hash_init('md5');
    PartitionHash::append($context, $disc, $partitionOffset, $shift);

    return hash_final($context);
}

it('hashes the header, the apploader and the dol segments read from the start of the disc', function (): void {
    $iso = syntheticGameCubeIso();

    expect(partitionMd5(inMemoryNintendoDisc($iso)))->toBe(syntheticGameCubeHash($iso));
});

it('hashes at most 1 mib of header', function (): void {
    $dolTable = str_repeat("\0", 0xD8);
    $header = nintendoPartitionHeader(0x200000, 0x10, 0x100000, 0x100000);

    expect(partitionMd5(inMemoryNintendoDisc($header . $dolTable)))->toBe(md5($header));
});

it('shifts the offsets and sizes of a wii partition', function (): void {
    $partition = nintendoPartitionHeader(0x10, 0x10, 0x3000 >> 2, 0x3000)
        . pack('N', 0x3100 >> 2) . str_repeat("\0", 0x8C) . pack('N', 0x40 >> 2) . str_repeat("\0", 0x44)
        . str_repeat("\0", 0x28) . noiseBytes('wii segment', 0x40);
    $disc = noiseBytes('before the partition', 0x500) . $partition;

    expect(partitionMd5(inMemoryNintendoDisc($disc), 0x500, 2))
        ->toBe(md5(mb_substr($partition, 0, 0x2480, '8bit') . noiseBytes('wii segment', 0x40)));
});

it('hashes long segments whole', function (): void {
    $segment = noiseBytes('long segment', 0x100010);
    $header = nintendoPartitionHeader(0x10, 0x10, 0x2500, 0x2500);
    $dolTable = pack('N', 0x2600) . str_repeat("\0", 0x8C) . pack('N', 0x100010) . str_repeat("\0", 0x44);

    expect(partitionMd5(inMemoryNintendoDisc($header . $dolTable . str_repeat("\0", 0x28) . $segment)))
        ->toBe(md5(mb_substr($header, 0, 0x2480, '8bit') . $segment));
});

it('rejects apploader sizes that overflow 32 bits', function (): void {
    $disc = inMemoryNintendoDisc(nintendoPartitionHeader(0xFFFFFFFF, 0xFFFFDBB0, 0x2500, 0x2600));

    expect(fn (): string => partitionMd5($disc))->toThrow(RuntimeException::class, 'The apploader sizes overflow');
});

it('throws on segments past the end of the disc', function (): void {
    $iso = syntheticGameCubeIso();
    $disc = inMemoryNintendoDisc(mb_substr($iso, 0, 0x80000, '8bit'));

    expect(fn (): string => partitionMd5($disc))->toThrow(RuntimeException::class, 'Past the end of the disc.');
});
