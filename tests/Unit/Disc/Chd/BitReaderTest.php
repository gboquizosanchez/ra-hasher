<?php

declare(strict_types=1);

use Boquizo\RaHasher\Disc\Chd\BitReader;

it('reads values most significant bit first across bytes', function (): void {
    $reader = new BitReader(chdBits([[5, 3], [0, 0], [0x1234, 13], [0xDEADBEEF, 32], [1, 1]]));

    expect($reader->read(3))->toBe(5)
        ->and($reader->read(0))->toBe(0)
        ->and($reader->peek(13))->toBe(0x1234)
        ->and($reader->read(13))->toBe(0x1234)
        ->and($reader->read(32))->toBe(0xDEADBEEF)
        ->and($reader->read(1))->toBe(1)
        ->and($reader->overflowed())->toBeFalse();
});

it('skips bits', function (): void {
    $reader = new BitReader("\x0F\xF0");
    $reader->skip(4);

    expect($reader->read(8))->toBe(0xFF);
});

it('reads zeros past the end and tells it overflowed', function (): void {
    $reader = new BitReader("\xFF");

    expect($reader->read(8))->toBe(0xFF)
        ->and($reader->overflowed())->toBeFalse()
        ->and($reader->read(32))->toBe(0)
        ->and($reader->overflowed())->toBeTrue();
});
