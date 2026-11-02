<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Hashers;

use Boquizo\RaHasher\Disc\DiscTrack;
use Boquizo\RaHasher\Disc\Iso9660;
use HashContext;
use Override;

/**
 * MD5 of the contents of the .PRG files listed in IPL.TXT, in that order
 * (rc_hash_neogeo_cd). The list is read up to its first 1023 bytes.
 */
final readonly class NeoGeoCdHasher extends DiscHasher
{
    private const string LIST_FILE = 'IPL.TXT';

    private const int LIST_BYTES = 1024;

    private const string PROGRAM_EXTENSION = '.PRG';

    private const string END_OF_TEXT = "\x1a";

    #[Override]
    protected function hashFiles(HashContext $context, DiscTrack $track): bool
    {
        $list = Iso9660::findFile($track, self::LIST_FILE);

        if ($list === null) {
            return false;
        }

        $read = $track->readSector($list['sector'], self::LIST_BYTES);

        if ($read === '') {
            return false;
        }

        $text = mb_substr($read, 0, self::LIST_BYTES - 1, '8bit');
        $length = mb_strlen($text, '8bit');
        $position = 0;

        do {
            $start = $position;

            while ($position < $length && $text[$position] !== "\0" && $text[$position] !== '.') {
                $position++;
            }

            if (strncasecmp(mb_substr($text, $position, 4, '8bit'), self::PROGRAM_EXTENSION, 4) === 0) {
                $name = mb_substr($text, $start, $position + 4 - $start, '8bit');
                $position += 5;
                $program = Iso9660::findFile($track, $name);

                if ($program === null || ! $this->hashFile($context, $track, $program['sector'], $program['size'])) {
                    return false;
                }
            }

            while ($position < $length && $text[$position] !== "\0" && $text[$position] !== "\n") {
                $position++;
            }

            if ($position >= $length || $text[$position] !== "\n") {
                break;
            }

            $position++;
        } while ($position < $length && $text[$position] !== "\0" && $text[$position] !== self::END_OF_TEXT);

        return true;
    }
}
