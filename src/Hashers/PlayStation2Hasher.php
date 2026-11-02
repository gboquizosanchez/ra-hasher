<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Hashers;

use Boquizo\RaHasher\Disc\DiscTrack;
use HashContext;
use Override;

/**
 * MD5 of the name and the contents of the boot executable of a PlayStation 2
 * disc, named by the BOOT2 line of SYSTEM.CNF (rc_hash_ps2).
 */
final readonly class PlayStation2Hasher extends DiscHasher
{
    use FindsPlayStationExecutable;

    private const int SIGNATURE_BYTES = 4;

    #[Override]
    protected function hashFiles(HashContext $context, DiscTrack $track): bool
    {
        $executable = $this->findBootExecutable($track, 'BOOT2', 'cdrom0:');

        if ($executable === null || mb_strlen($track->readSector($executable['sector'], self::SIGNATURE_BYTES), '8bit') < self::SIGNATURE_BYTES) {
            return false;
        }

        hash_update($context, $executable['name']);

        return $this->hashFile($context, $track, $executable['sector'], $executable['size']);
    }
}
