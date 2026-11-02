<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Hashers;

use Boquizo\RaHasher\GameHasher;
use Override;
use SplFileInfo;

/**
 * MD5 of the big endian (.z64) version of the ROM (rc_hash_n64): byteswapped
 * (.v64) and little endian (.n64) dumps are converted word by word while hashing. Unknown first bytes are left to RAHasher.
 * The words are swapped by converting them between the big and little endian
 * UCS-2 or UCS-4 encodings, which mbstring does in C and is an order of
 * magnitude faster than unpacking and packing them.
 */
final readonly class Nintendo64Hasher implements GameHasher
{
    private const string UCS4_ERROR_MARKER = "\xFF\xFF\xFF\xFF";

    #[Override]
    public function hash(SplFileInfo $file): ?string
    {
        $stream = $file->openFile('rb');
        $firstByte = (string) $stream->fread(1);

        $wordSize = match ($firstByte) {
            "\x80", "\xE8", '"' => 0,
            "\x37" => 2,
            "\x40" => 4,
            default => null,
        };

        if ($wordSize === null) {
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

            if ($wordSize !== 0) {
                $chunk = $this->swap($chunk, $wordSize);
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
     *
     * mbstring keeps every 16-bit value, but turns the 32-bit word FFFFFFFF
     * (its error marker) into "?", so a chunk with those bytes is swapped by
     * unpacking and packing it instead.
     */
    private function swap(string $chunk, int $wordSize): string
    {
        $size = mb_strlen($chunk, '8bit');
        $wordsLength = $size - ($size % $wordSize);
        $words = mb_substr($chunk, 0, $wordsLength, '8bit');
        $rest = mb_substr($chunk, $wordsLength, null, '8bit');

        $swapped = match (true) {
            $wordSize === 2 => mb_convert_encoding($words, 'UCS-2LE', 'UCS-2BE'),
            str_contains($words, self::UCS4_ERROR_MARKER) => pack('V*', ...array_values((array) unpack('N*', $words))),
            default => mb_convert_encoding($words, 'UCS-4LE', 'UCS-4BE'),
        };

        return $swapped . $rest;
    }
}
