<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Hashers;

use Boquizo\RaHasher\Disc\DiscTrack;
use Boquizo\RaHasher\Disc\Iso9660;
use HashContext;
use Override;

/**
 * MD5 of PSP_GAME\PARAM.SFO followed by PSP_GAME\SYSDIR\EBOOT.BIN of a PSP
 * disc image (rc_hash_psp). Their names are not part of the hash.
 */
final readonly class PlayStationPortableHasher extends DiscHasher
{
    private const array FILES = ['PSP_GAME\\PARAM.SFO', 'PSP_GAME\\SYSDIR\\EBOOT.BIN'];

    #[Override]
    protected function hashFiles(HashContext $context, DiscTrack $track): bool
    {
        foreach (self::FILES as $path) {
            $file = Iso9660::findFile($track, $path);

            if ($file === null || ! $this->hashFile($context, $track, $file['sector'], $file['size'])) {
                return false;
            }
        }

        return true;
    }
}
