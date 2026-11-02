<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Hashers;

use Boquizo\RaHasher\Disc\DiscTrack;
use Boquizo\RaHasher\Disc\Iso9660;
use Boquizo\RaHasher\GameHasher;
use HashContext;

/**
 * The hash of a PC Engine CD track, shared by the system and by the PC-FX
 * discs that identify as one (rc_hash_pce_track).
 */
trait HashesPcEngineTrack
{
    private const string PCE_IDENTIFIER = 'PC Engine CD-ROM SYSTEM';

    private const string BOOT_FILE = 'BOOT.BIN';

    private const int HEADER_BYTES = 128;

    /**
     * Hashes the title and the program of the boot header or, on a GameExpress
     * disc, BOOT.BIN, whose sector is absolute: rcheevos does not add the first
     * sector of the track to it. False when the track is not a PC Engine CD.
     */
    private function hashPcEngineTrack(HashContext $context, DiscTrack $track): bool
    {
        $header = $track->readSector($track->firstSector() + 1, self::HEADER_BYTES);

        if (mb_strlen($header, '8bit') < self::HEADER_BYTES) {
            return false;
        }

        if ($this->hasPcEngineSignature($header)) {
            return $this->hashPcEngineProgram($context, $track, $header);
        }

        $file = Iso9660::findFile($track, self::BOOT_FILE);

        if ($file === null || $file['size'] >= GameHasher::MAX_BUFFER_SIZE) {
            return false;
        }

        return $this->hashSectors($context, $track, $file['sector'], $file['size']);
    }

    /** Whether the 128 bytes of the second sector of the track start a PC Engine CD boot header. */
    private function hasPcEngineSignature(string $header): bool
    {
        return mb_substr($header, 32, 23, '8bit') === self::PCE_IDENTIFIER;
    }

    private function hashPcEngineProgram(HashContext $context, DiscTrack $track, string $header): bool
    {
        hash_update($context, mb_substr($header, 106, 22, '8bit'));

        $sector = (ord($header[0]) << 16) + (ord($header[1]) << 8) + ord($header[2]) + $track->firstSector();

        return $this->hashSectors($context, $track, $sector, ord($header[3]) * DiscTrack::SECTOR_SIZE);
    }
}
