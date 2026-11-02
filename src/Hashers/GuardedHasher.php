<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Hashers;

use Boquizo\RaHasher\GameHasher;
use Override;
use RuntimeException;
use SplFileInfo;

/**
 * Leaves to RAHasher what the wrapped hasher cannot do like it: .zip files
 * (RAHasher hashes their inner content, except for arcade) and files that
 * cannot be opened (RAHasher then fails and the caller logs it).
 */
final readonly class GuardedHasher implements GameHasher
{
    public function __construct(
        private GameHasher $hasher,
        private bool $leavesZipToRaHasher,
    ) {}

    public function inner(): GameHasher
    {
        return $this->hasher;
    }

    #[Override]
    public function hash(SplFileInfo $file): ?string
    {
        if ($this->leavesZipToRaHasher && mb_strtolower($file->getExtension()) === 'zip') {
            return null;
        }

        try {
            return $this->hasher->hash($file);
        } catch (RuntimeException) {
            return null;
        }
    }
}
