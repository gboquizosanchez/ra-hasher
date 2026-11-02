<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Hashers;

use Boquizo\RaHasher\Disc\DiscTrack;
use HashContext;
use Override;

/**
 * MD5 of the first 512 bytes of a Sega CD or Saturn disc, as long as they
 * start with the system identifier of one of them (rc_hash_sega_cd).
 */
final readonly class SegaCdHasher extends DiscHasher
{
    private const int HEADER_BYTES = 512;

    private const string SEGA_CD_IDENTIFIER = 'SEGADISCSYSTEM  ';

    private const string SATURN_IDENTIFIER = 'SEGA SEGASATURN ';

    #[Override]
    protected function hashFiles(HashContext $context, DiscTrack $track): bool
    {
        $header = $track->readSector(0, self::HEADER_BYTES);

        if (mb_strlen($header, '8bit') < self::HEADER_BYTES) {
            return false;
        }

        if (! (str_starts_with($header, self::SEGA_CD_IDENTIFIER) || str_starts_with($header, self::SATURN_IDENTIFIER))) {
            return false;
        }

        hash_update($context, $header);

        return true;
    }
}
