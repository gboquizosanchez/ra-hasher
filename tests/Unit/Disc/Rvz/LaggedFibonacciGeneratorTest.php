<?php

declare(strict_types=1);

use Boquizo\RaHasher\Disc\Rvz\LaggedFibonacciGenerator;

it('generates the padding of the seeds dolphin finds in it', function (int $block): void {
    $rvz = (string) file_get_contents(rvzFixturePath('synthetic-none'));
    $packedGroup = 100428 + $block * 112;
    $seed = mb_substr($rvz, $packedGroup + 4 + 0x22 + 4, LaggedFibonacciGenerator::SEED_BYTES, '8bit');
    $padding = mb_substr(syntheticGameCubeIso(), 0x60000 + $block * 0x8000 + 0x22, 0x8000 - 0x22, '8bit');

    expect(LaggedFibonacciGenerator::generate($seed, 0x22, 0x8000 - 0x22))->toBe($padding);
})->with([0, 3]);

it('generates zeros from a zero seed', function (): void {
    expect(LaggedFibonacciGenerator::generate(str_repeat("\0", LaggedFibonacciGenerator::SEED_BYTES), 5, 3000))
        ->toBe(str_repeat("\0", 3000));
});

it('skips the bytes before the requested ones', function (int $skip, int $length): void {
    $seed = noiseBytes('skip', LaggedFibonacciGenerator::SEED_BYTES);

    expect(LaggedFibonacciGenerator::generate($seed, $skip, $length))
        ->toBe(mb_substr(LaggedFibonacciGenerator::generate($seed, 0, $skip + $length), $skip, null, '8bit'));
})->with([
    'inside the first buffer' => [7, 100],
    'across buffers' => [2000, 5000],
    'past whole buffers' => [9000, 10],
]);
