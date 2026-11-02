<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Hashers;

use Boquizo\RaHasher\Disc\Nintendo\NintendoDisc;
use Boquizo\RaHasher\Disc\Nintendo\PlainDisc;
use Boquizo\RaHasher\Disc\Nintendo\WiiDiscHash;
use Boquizo\RaHasher\Disc\Nintendo\WiiWareHash;
use Boquizo\RaHasher\Disc\Wbfs\WbfsFile;
use Boquizo\RaHasher\GameHasher;
use Override;
use SplFileInfo;

/**
 * MD5 of a Wii disc (rc_hash_wii_disc) or a WiiWare title (rc_hash_wiiware),
 * told apart by their magic words like rc_hash_wii does, read from .iso and
 * .wad files or from WBFS images (split ones included). Other containers
 * (.rvz, whose Wii partitions would have to be encrypted again, .wia, .gcz,
 * .ciso) and files with neither magic word are left to RAHasher.
 */
final readonly class WiiHasher implements GameHasher
{
    private const string DISC_MAGIC = "\x5D\x1C\x9E\xA3";

    private const int DISC_MAGIC_OFFSET = 0x18;

    private const string WIIWARE_MAGIC = "Is\0\0";

    private const int WIIWARE_MAGIC_OFFSET = 0x04;

    #[Override]
    public function hash(SplFileInfo $file): ?string
    {
        $disc = $this->open($file);

        if (! $disc instanceof NintendoDisc) {
            return null;
        }

        $context = hash_init('md5');

        if ($disc->read(self::DISC_MAGIC_OFFSET, 4) === self::DISC_MAGIC) {
            WiiDiscHash::append($context, $disc);

            return hash_final($context);
        }

        if ($disc->read(self::WIIWARE_MAGIC_OFFSET, 4) === self::WIIWARE_MAGIC) {
            WiiWareHash::append($context, $disc);

            return hash_final($context);
        }

        return null;
    }

    private function open(SplFileInfo $file): ?NintendoDisc
    {
        return match (mb_strtolower($file->getExtension())) {
            'wbfs' => WbfsFile::open($file),
            'iso', 'wad' => PlainDisc::open($file),
            default => null,
        };
    }
}
