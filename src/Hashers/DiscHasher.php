<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Hashers;

use Boquizo\RaHasher\Disc\Chd\ChdFile;
use Boquizo\RaHasher\Disc\Chd\ChdTrack;
use Boquizo\RaHasher\Disc\DiscTrack;
use Boquizo\RaHasher\GameHasher;
use HashContext;
use Override;
use SplFileInfo;

/**
 * A system hashed from the files of the first track of a disc. Only CHD
 * images are read, the other formats are left to RAHasher.
 */
abstract readonly class DiscHasher implements GameHasher
{
    /** The track rcheevos opens to hash PlayStation discs. */
    private const int TRACK_NUMBER = 1;

    /** Feeds the hash with what identifies the disc, false when that is not found. */
    abstract protected function hashFiles(HashContext $context, DiscTrack $track): bool;

    #[Override]
    final public function hash(SplFileInfo $file): ?string
    {
        if (mb_strtolower($file->getExtension()) !== 'chd') {
            return null;
        }

        return $this->hashImage(ChdFile::open($file));
    }

    /** Null when rcheevos would not manage to hash the disc. */
    final public function hashTrack(DiscTrack $track): ?string
    {
        $context = hash_init('md5');

        return $this->hashFiles($context, $track) ? hash_final($context) : null;
    }

    /** Opens the track the system is hashed from, the first one unless the system says otherwise. */
    protected function hashImage(ChdFile $chd): ?string
    {
        return $this->hashTrack(ChdTrack::open($chd, self::TRACK_NUMBER));
    }

    /**
     * Hashes a file sector by sector, no more than MAX_BUFFER_SIZE bytes of
     * it (rc_hash_cd_file). False when its first sector cannot be read.
     */
    protected function hashFile(HashContext $context, DiscTrack $track, int $sector, int $size): bool
    {
        $chunk = $track->readSector($sector, DiscTrack::SECTOR_SIZE);

        if (mb_strlen($chunk, '8bit') < DiscTrack::SECTOR_SIZE) {
            return false;
        }

        $size = min($size, self::MAX_BUFFER_SIZE);

        if ($size < DiscTrack::SECTOR_SIZE) {
            $chunk = mb_substr($chunk, 0, $size, '8bit');
        }

        while (true) {
            hash_update($context, $chunk);
            $hashed = mb_strlen($chunk, '8bit');

            if ($size <= $hashed) {
                return true;
            }

            $size = max(0, $size - $hashed);
            $chunk = $track->readSector(++$sector, min($size, DiscTrack::SECTOR_SIZE));

            if ($chunk === '') {
                return true;
            }
        }
    }

    /**
     * Hashes the bytes from the sector on, sector by sector. False when a
     * read does not return all the bytes asked for.
     */
    protected function hashSectors(HashContext $context, DiscTrack $track, int $sector, int $bytes): bool
    {
        while ($bytes > 0) {
            $size = min($bytes, DiscTrack::SECTOR_SIZE);
            $chunk = $track->readSector($sector++, $size);

            if (mb_strlen($chunk, '8bit') < $size) {
                return false;
            }

            hash_update($context, $chunk);
            $bytes -= $size;
        }

        return true;
    }
}
