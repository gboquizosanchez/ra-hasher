<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Disc\Nintendo;

use RuntimeException;

/**
 * A GameCube or Wii optical disc, read at byte offsets of the disc whatever
 * the container it is stored in.
 */
interface NintendoDisc
{
    /**
     * The bytes of the disc from the offset on, all of them.
     *
     * @throws RuntimeException when the range does not lie inside the disc or cannot be read
     */
    public function read(int $offset, int $length): string;

    /** The bytes of the whole disc, the size of its ISO image. */
    public function size(): int;
}
