<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Hashers;

use Boquizo\RaHasher\Disc\Nintendo\NintendoDisc;
use Boquizo\RaHasher\Disc\Nintendo\PartitionHash;
use Boquizo\RaHasher\Disc\Nintendo\PlainDisc;
use Boquizo\RaHasher\Disc\Rvz\RvzFile;
use Boquizo\RaHasher\GameHasher;
use Override;
use SplFileInfo;

/**
 * MD5 of the disc header, the apploader and the boot DOL segments
 * (rc_hash_gamecube), read from .iso and .gcm images or from Dolphin's .rvz
 * ones. Other containers (.wia, .gcz, .ciso) and discs without the GameCube
 * magic word are left to RAHasher.
 */
final readonly class GameCubeHasher implements GameHasher
{
    private const string MAGIC = "\xC2\x33\x9F\x3D";

    private const int MAGIC_OFFSET = 0x1C;

    #[Override]
    public function hash(SplFileInfo $file): ?string
    {
        $disc = $this->open($file);

        if (! $disc instanceof NintendoDisc || $disc->read(self::MAGIC_OFFSET, 4) !== self::MAGIC) {
            return null;
        }

        $context = hash_init('md5');
        PartitionHash::append($context, $disc);

        return hash_final($context);
    }

    private function open(SplFileInfo $file): ?NintendoDisc
    {
        return match (mb_strtolower($file->getExtension())) {
            'rvz' => RvzFile::open($file),
            'iso', 'gcm' => PlainDisc::open($file),
            default => null,
        };
    }
}
