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
 * MD5 of the boot header and the program of a PC-FX disc, looked for in the
 * largest data track and then in track 2. Some discs identify as PC Engine CD
 * ones and are hashed as such (rc_hash_pcfx_cd).
 */
final readonly class PcFxHasher extends DiscHasher
{
    use HashesPcEngineTrack;

    private const string PCFX_IDENTIFIER = 'PC-FX:Hu_CD-ROM';

    private const int SECOND_TRACK = 2;

    #[Override]
    protected function hashImage(ChdFile $chd): ?string
    {
        $track = ChdTrack::open($chd, SpecialTrack::Largest);

        if (! $this->hasPcFxIdentifier($track)) {
            $track = ChdTrack::open($chd, self::SECOND_TRACK);
        }

        return $this->hashTrack($track);
    }

    #[Override]
    protected function hashFiles(HashContext $context, DiscTrack $track): bool
    {
        $header = $track->readSector($track->firstSector() + 1, self::HEADER_BYTES);

        if (mb_strlen($header, '8bit') < self::HEADER_BYTES) {
            return false;
        }

        if (! $this->hasPcFxIdentifier($track)) {
            return $this->hasPcEngineSignature($header) && $this->hashPcEngineTrack($context, $track);
        }

        hash_update($context, $header);

        $sector = $this->littleEndian($header, 32) + $track->firstSector();

        return $this->hashSectors($context, $track, $sector, $this->littleEndian($header, 36) * DiscTrack::SECTOR_SIZE);
    }

    private function hasPcFxIdentifier(DiscTrack $track): bool
    {
        return str_starts_with($track->readSector($track->firstSector(), 32), self::PCFX_IDENTIFIER);
    }

    /** Three bytes, least significant first. */
    private function littleEndian(string $buffer, int $offset): int
    {
        return (ord($buffer[$offset + 2]) << 16) + (ord($buffer[$offset + 1]) << 8) + ord($buffer[$offset]);
    }
}
