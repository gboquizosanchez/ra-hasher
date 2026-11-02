<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Hashers;

use Override;
use SplFileInfo;

/**
 * MD5 of the Intel HEX text with every line ending normalized to "\n",
 * including one after the last line (rc_hash_text). Arduboy FX packages
 * (.arduboy) are left to RAHasher.
 */
final readonly class ArduboyHasher extends BufferedHasher
{
    #[Override]
    protected function supports(SplFileInfo $file): bool
    {
        $extension = mb_strtolower($file->getExtension());

        return $extension !== 'arduboy';
    }

    #[Override]
    protected function hashBuffer(string $buffer): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $buffer);

        if (! str_ends_with($text, "\n")) {
            $text .= "\n";
        }

        return md5($text);
    }
}
