<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Disc;

/**
 * The tracks of a disc picked by their contents rather than their number,
 * with the values of the RC_HASH_CDTRACK_* constants of rcheevos.
 */
enum SpecialTrack: int
{
    /** The first track that is not audio. */
    case FirstData = -1;

    /** The last track, audio or not. */
    case Last = -2;

    /** The track with the most sectors that is not audio. */
    case Largest = -3;

    /** The first track of the second session of a multisession disc. */
    case FirstOfSecondSession = -4;
}
