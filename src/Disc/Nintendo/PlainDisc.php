<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Disc\Nintendo;

use Override;
use RuntimeException;
use SplFileInfo;
use SplFileObject;

/**
 * A disc stored as is, byte for byte, like the .iso and .gcm images. The
 * size is the one of the open file (fstat), so every range inside it reads whole.
 */
final readonly class PlainDisc implements NintendoDisc
{
    private function __construct(
        private SplFileObject $file,
        private int $size,
    ) {}

    public static function open(SplFileInfo $file): self
    {
        $stream = $file->openFile('rb');

        /** @var array{size: int} $stat */
        $stat = $stream->fstat();

        return new self($stream, $stat['size']);
    }

    #[Override]
    public function read(int $offset, int $length): string
    {
        if ($offset < 0 || $length < 0 || $offset > $this->size || $length > $this->size - $offset) {
            throw new RuntimeException('The disc image ends before the data it points to.');
        }

        if ($length === 0) {
            return '';
        }

        $this->file->fseek($offset);

        return (string) $this->file->fread($length);
    }

    #[Override]
    public function size(): int
    {
        return $this->size;
    }
}
