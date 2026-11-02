<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Disc;

/**
 * A track of a disc image opened for hashing, like the track handles of the
 * rcheevos cdreader: its sectors are addressed by their absolute number on
 * the disc and only their user data is read.
 */
interface DiscTrack
{
    /** The bytes of user data in a sector. */
    public const int SECTOR_SIZE = 2048;

    /**
     * Up to the requested bytes of user data from the sector on, carrying on
     * into the following sectors when one sector does not hold them all.
     * Fewer bytes are returned when the image ends first, and none for a
     * sector outside the track.
     *
     * @param  int<0, max>  $bytes
     */
    public function readSector(int $sector, int $bytes): string;

    /**
     * The absolute number of the first sector of the track.
     */
    public function firstSector(): int;
}
