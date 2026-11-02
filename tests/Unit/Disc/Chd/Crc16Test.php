<?php

declare(strict_types=1);

use Boquizo\RaHasher\Disc\Chd\Crc16;

it('computes crc-16/ccitt with an initial value of 0xffff', function (string $data, int $crc): void {
    expect(Crc16::compute($data))->toBe($crc);
})->with([
    'check value' => ['123456789', 0x29B1],
    'no data' => ['', 0xFFFF],
    'zero bytes' => ["\0\0\0\0", 0x84C0],
]);
