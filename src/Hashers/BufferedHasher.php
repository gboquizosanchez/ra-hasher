<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Hashers;

use Boquizo\RaHasher\GameHasher;
use Override;
use SplFileInfo;

/**
 * Reads the file into memory, like rc_hash_buffered_file, so the hash can
 * depend on its header or size.
 */
abstract readonly class BufferedHasher implements GameHasher
{
    abstract protected function hashBuffer(string $buffer): string;

    abstract protected function supports(SplFileInfo $file): bool;

    #[Override]
    final public function hash(SplFileInfo $file): ?string
    {
        if (! $this->supports($file)) {
            return null;
        }

        $length = min($file->getSize(), self::MAX_BUFFER_SIZE);
        $buffer = '';

        if ($length > 0) {
            $stream = $file->openFile('rb');
            $buffer = (string) $stream->fread($length);
        }

        return $this->hashBuffer($buffer);
    }
}
