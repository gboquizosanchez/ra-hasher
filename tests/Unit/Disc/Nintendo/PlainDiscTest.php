<?php

declare(strict_types=1);

use Boquizo\RaHasher\Disc\Nintendo\PlainDisc;

beforeEach(function (): void {
    $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'plain-disc-' . uniqid() . '.iso';
    file_put_contents($this->path, 'GAMECUBE DISC');
    $this->disc = PlainDisc::open(new SplFileInfo($this->path));
});

afterEach(function (): void {
    $this->disc = null;
    unlink($this->path);
});

it('reads the bytes of the image at any offset', function (): void {
    expect($this->disc->size())->toBe(13)
        ->and($this->disc->read(0, 8))->toBe('GAMECUBE')
        ->and($this->disc->read(9, 4))->toBe('DISC')
        ->and($this->disc->read(13, 0))->toBe('');
});

it('throws on ranges outside the image', function (int $offset, int $length): void {
    expect(fn (): string => $this->disc->read($offset, $length))
        ->toThrow(RuntimeException::class, 'The disc image ends before the data it points to.');
})->with([
    'negative offset' => [-1, 4],
    'negative length' => [0, -1],
    'offset past the end' => [14, 0],
    'range past the end' => [10, 4],
]);
