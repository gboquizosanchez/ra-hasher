<?php

declare(strict_types=1);

use Boquizo\RaHasher\Compression\LzmaDecoder;

/**
 * A raw LZMA1 stream of tests/Fixtures/Lzma. All but the one without end
 * marker (from a .7z archive made by 7-Zip) come from liblzma, which ends
 * raw LZMA1 streams with an end marker.
 */
function lzmaFixture(string $name): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Lzma/' . $name . '.lzma');
}

it('decodes raw lzma1 streams', function (string $fixture, int $size, string $md5, int $lc, int $lp, int $pb): void {
    $decoded = LzmaDecoder::decode(lzmaFixture($fixture), $size, $lc, $lp, $pb);

    expect(mb_strlen($decoded, '8bit'))->toBe($size)
        ->and(md5($decoded))->toBe($md5);
})->with([
    'no data' => ['empty', 0, 'd41d8cd98f00b204e9800998ecf8427e', 3, 0, 2],
    'one byte' => ['one-byte', 1, '9dd4e461268c8034f5c8564e155c67a6', 3, 0, 2],
    'literals only' => ['literals', 300, '693cc569baa071a8adbdd268a9c078b4', 3, 0, 2],
    'long repeated matches' => ['repeats', 14200, 'e602a31784f62352e19637703e2261f8', 3, 0, 2],
    'text, edited copies and records' => ['mixed', 40000, '759479279066b63bd21dd7e72333610f', 3, 0, 2],
    'no end marker, like chd hunks' => ['mixed-without-end-marker', 40000, '5eb7253df2f5494b9bdb7c471fcf7bd5', 3, 0, 2],
    'other literal and position bits' => ['records-lc0-lp2-pb0', 24000, '868f09ef445ebfd982109921fea5e09a', 0, 2, 0],
]);

it('rejects streams without a range coder header', function (string $compressed): void {
    expect(fn (): string => LzmaDecoder::decode($compressed, 300))
        ->toThrow(RuntimeException::class, 'does not start with a range coder header');
})->with([
    'too short' => ["\0\0\0\0"],
    'first byte not zero' => ["\x01" . mb_substr(lzmaFixture('literals'), 1, null, '8bit')],
    'code out of range' => ["\0\xFF\xFF\xFF\xFF" . str_repeat("\0", 16)],
]);

it('rejects truncated streams', function (int $length): void {
    $truncated = mb_substr(lzmaFixture('mixed-without-end-marker'), 0, $length, '8bit');

    expect(fn (): string => LzmaDecoder::decode($truncated, 40000))->toThrow(RuntimeException::class);
})->with([
    'half of it' => [5962],
    'all but the last byte' => [11924],
]);

it('rejects streams that do not decode to the expected size', function (string $fixture, int $size, string $message): void {
    expect(fn (): string => LzmaDecoder::decode(lzmaFixture($fixture), $size))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'a literal after the expected size' => ['literals', 299, 'decodes to more data than expected'],
    'a match past the expected size' => ['repeats', 14199, 'decodes to more data than expected'],
    'an end marker before the expected size' => ['repeats', 14201, 'does not end where its data does'],
    'no end marker and less data than expected' => ['mixed-without-end-marker', 40001, 'does not end where its data does'],
]);

it('rejects data after the end of the stream', function (): void {
    expect(fn (): string => LzmaDecoder::decode(lzmaFixture('mixed-without-end-marker') . "\0", 40000))
        ->toThrow(RuntimeException::class, 'does not end where its data does');
});

it('rejects matches that refer to data before the start', function (): void {
    expect(fn (): string => LzmaDecoder::decode("\0\xFF\xFF\xFF\xFE" . str_repeat("\0", 16), 10))
        ->toThrow(RuntimeException::class, 'refers to data before its start');
});
