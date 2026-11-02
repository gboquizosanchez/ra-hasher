<?php

declare(strict_types=1);

namespace Boquizo\RaHasher;

use SplFileInfo;

/**
 * Calculates the RetroAchievements hash of a game file in PHP, following
 * https://docs.retroachievements.org/developer-docs/game-identification.html
 * and rcheevos (src/hash).
 */
interface GameHasher
{
    /** Bytes rcheevos reads at most from a file (MAX_BUFFER_SIZE). */
    public const int MAX_BUFFER_SIZE = 64 * 1024 * 1024;

    /** Bytes read at a time when a file is streamed. */
    public const int CHUNK_SIZE = 65536;

    /** Returns null when the file has to be hashed by RAHasher instead. */
    public function hash(SplFileInfo $file): ?string;
}
