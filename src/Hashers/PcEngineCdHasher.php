<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Hashers;

use Boquizo\RaHasher\Disc\Chd\ChdFile;
use Boquizo\RaHasher\Disc\Chd\ChdTrack;
use Boquizo\RaHasher\Disc\DiscTrack;
use Boquizo\RaHasher\Disc\SpecialTrack;
use HashContext;
use Override;

/**
 * MD5 of the title and the program of a PC Engine CD or TurboGrafx-CD disc,
 * read from its first data track (rc_hash_pce_cd).
 */
final readonly class PcEngineCdHasher extends DiscHasher
{
    use HashesPcEngineTrack;

    #[Override]
    protected function hashImage(ChdFile $chd): ?string
    {
        return $this->hashTrack(ChdTrack::open($chd, SpecialTrack::FirstData));
    }

    #[Override]
    protected function hashFiles(HashContext $context, DiscTrack $track): bool
    {
        return $this->hashPcEngineTrack($context, $track);
    }
}
