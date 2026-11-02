<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Hashers;

use Boquizo\RaHasher\GameHasher;
use Override;
use SplFileInfo;

/**
 * MD5 of the whole file (rc_hash_whole_file). Playlists (.m3u) are left to
 * RAHasher, which hashes their first disc.
 */
final readonly class WholeFileHasher implements GameHasher
{
    #[Override]
    public function hash(SplFileInfo $file): ?string
    {
        $extension = mb_strtolower($file->getExtension());

        if ($extension === 'm3u') {
            return null;
        }

        $stream = $file->openFile('rb');
        $context = hash_init('md5');
        $remaining = self::MAX_BUFFER_SIZE;

        while ($remaining > 0) {
            if ($stream->eof()) {
                break;
            }

            $length = min(self::CHUNK_SIZE, $remaining);
            $chunk = (string) $stream->fread($length);
            $remaining -= mb_strlen($chunk, '8bit');

            hash_update($context, $chunk);
        }

        return hash_final($context);
    }
}
