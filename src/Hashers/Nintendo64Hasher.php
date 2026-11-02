<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Hashers;

use Boquizo\RaHasher\GameHasher;
use Override;
use SplFileInfo;

/**
 * MD5 of the big endian (.z64) version of the ROM (rc_hash_n64): byteswapped
 * (.v64) and little endian (.n64) dumps are converted word by word while hashing. Unknown first bytes are left to RAHasher.
 */
final readonly class Nintendo64Hasher implements GameHasher
{
    #[Override]
    public function hash(SplFileInfo $file): ?string
    {
        $stream = $file->openFile('rb');
        $firstByte = (string) $stream->fread(1);

        $formats = match ($firstByte) {
            "\x80", "\xE8", '"' => [],
            "\x37" => ['n*', 'v*', 2],
            "\x40" => ['N*', 'V*', 4],
            default => null,
        };

        if ($formats === null) {
            return null;
        }

        $stream->rewind();
        $context = hash_init('md5');
        $remaining = self::MAX_BUFFER_SIZE;

        while ($remaining > 0) {
            if ($stream->eof()) {
                break;
            }

            $length = min(self::CHUNK_SIZE, $remaining);
            $chunk = (string) $stream->fread($length);
            $remaining -= mb_strlen($chunk, '8bit');

            if ($formats !== []) {
                $chunk = $this->swap($chunk, $formats[0], $formats[1], $formats[2]);
            }

            hash_update($context, $chunk);
        }

        return hash_final($context);
    }

    /**
     * Swaps the bytes of every complete word of the given size. rcheevos also
     * walks over a trailing partial word, but it reads past the end of the
     * file there, so the bytes that are not part of a complete word stay as
     * they are.
     */
    private function swap(string $chunk, string $from, string $to, int $wordSize): string
    {
        $size = mb_strlen($chunk, '8bit');
        $wordsLength = $size - ($size % $wordSize);
        $words = mb_substr($chunk, 0, $wordsLength, '8bit');
        $rest = mb_substr($chunk, $wordsLength, null, '8bit');
        $values = (array) unpack($from, $words);
        $swapped = pack($to, ...$values);

        return $swapped . $rest;
    }
}
