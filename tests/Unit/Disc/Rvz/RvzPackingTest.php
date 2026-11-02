<?php

declare(strict_types=1);

use Boquizo\RaHasher\Disc\Rvz\LaggedFibonacciGenerator;
use Boquizo\RaHasher\Disc\Rvz\RvzPacking;

it('unpacks stored runs and padding placed inside its 32 kib block', function (): void {
    $seed = noiseBytes('packing', LaggedFibonacciGenerator::SEED_BYTES);
    $packed = pack('N', 4) . 'DATA' . pack('N', 0x80000000 | 0x100) . $seed . pack('N', 3) . 'END';

    expect(RvzPacking::unpack($packed, 0x18003, 0x107))
        ->toBe('DATA' . LaggedFibonacciGenerator::generate($seed, 7, 0x100) . 'END');
});

it('rejects packed data that does not unpack to its group', function (string $packed, string $message): void {
    expect(fn (): string => RvzPacking::unpack($packed, 0, 8))->toThrow(RuntimeException::class, $message);
})->with([
    'cut inside a run size' => [pack('N', 4) . 'DATA' . 'ab', 'The RVZ packed data ends inside a run size.'],
    'run past the group' => [pack('N', 9) . 'DATADATAD', 'The RVZ packed data unpacks past the end of its group.'],
    'cut inside a run' => [pack('N', 8) . 'DAT', 'The RVZ packed data ends inside a run.'],
    'cut inside a seed' => [pack('N', 0x80000008) . 'seed', 'The RVZ packed data ends inside a run.'],
    'group not filled' => [pack('N', 4) . 'DATA', 'The RVZ packed data does not fill its group.'],
]);
