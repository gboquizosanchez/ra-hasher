<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Hashers;

use Boquizo\RaHasher\GameHasher;
use Closure;
use Override;
use SplFileInfo;
use SplFileObject;

/**
 * MD5 of the file without its header when it has one (rc_hash_nes,
 * rc_hash_snes, rc_hash_pce, rc_hash_7800, and rc_hash_lynx). Like rcheevos, only the
 * first MAX_BUFFER_SIZE bytes count, but the file is streamed instead of
 * being read into memory.
 */
final readonly class HeaderSkippingHasher implements GameHasher
{
    /** @param Closure(string, int): bool $hasHeader receives the first bytes and the hashed size */
    private function __construct(
        private int $headerSize,
        private Closure $hasHeader,
        private ?int $minimumSize = null,
    ) {}

    /** NES and Famicom Disk System: "NES\x1a" or "FDS\x1a", 16 byte header. */
    public static function nes(): self
    {
        return new self(16, static fn (string $head): bool => str_starts_with($head, "NES\x1a") || str_starts_with($head, "FDS\x1a"));
    }

    /** Super Nintendo: 512 bytes more than a multiple of 8 KB. */
    public static function snes(): self
    {
        return new self(512, static fn (string $head, int $size): bool => $size % 0x2000 === 512, minimumSize: 0);
    }

    /** PC Engine and SuperGrafx: the 512 bit of the size is set, 512 byte header. */
    public static function pcEngine(): self
    {
        return new self(512, static fn (string $head, int $size): bool => ($size & 512) !== 0, minimumSize: 0);
    }

    /** Atari 7800: "ATARI7800" from the second byte, 128 byte header. */
    public static function atari7800(): self
    {
        return new self(128, static function (string $head): bool {
            $signature = mb_substr($head, 1, 9, '8bit');

            return $signature === 'ATARI7800';
        });
    }

    /** Atari Lynx: "LYNX\0", 64 byte header. */
    public static function lynx(): self
    {
        return new self(64, static fn (string $head): bool => str_starts_with($head, "LYNX\0"));
    }

    #[Override]
    public function hash(SplFileInfo $file): string
    {
        $stream = $file->openFile('rb');
        $size = min($stream->fstat()['size'], self::MAX_BUFFER_SIZE);
        $head = $size > 0 ? (string) $stream->fread(min($size, $this->headerSize)) : '';

        $minimumSize = $this->minimumSize ?? $this->headerSize;
        $hasHeader = $size > $minimumSize && ($this->hasHeader)($head, $size);
        $offset = $hasHeader ? $this->headerSize : 0;

        return $this->hashRange($stream, $offset, $size - $offset);
    }

    private function hashRange(SplFileObject $stream, int $offset, int $length): string
    {
        $context = hash_init('md5');
        $stream->fseek($offset);

        while ($length > 0 && ! $stream->eof()) {
            $chunk = (string) $stream->fread(min(self::CHUNK_SIZE, $length));
            $length -= mb_strlen($chunk, '8bit');

            hash_update($context, $chunk);
        }

        return hash_final($context);
    }
}
