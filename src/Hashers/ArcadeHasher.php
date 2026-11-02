<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Hashers;

use Boquizo\RaHasher\GameHasher;
use Override;
use SplFileInfo;

/**
 * MD5 of the file name without its extension, case-sensitive (rc_hash_arcade).
 * Geolith .neo carts and FBNeo subsystem folders are left to RAHasher.
 */
final readonly class ArcadeHasher implements GameHasher
{
    /** @var list<string> */
    private const array SUBSYSTEM_FOLDERS = [
        'channelf',
        'chf',
        'coleco',
        'colecovision',
        'fds',
        'gamegear',
        'genesis',
        'mastersystem',
        'megadriv',
        'megadrive',
        'msx',
        'msx1',
        'neocd',
        'nes',
        'ngp',
        'pce',
        'pcengine',
        'sg1000',
        'sgx',
        'sms',
        'spectrum',
        'supergrafx',
        'tg16',
        'zxspectrum',
    ];

    #[Override]
    public function hash(SplFileInfo $file): ?string
    {
        $folderName = basename($file->getPath());
        $folder = mb_strtolower($folderName);
        $extension = $file->getExtension();
        $isSubsystem = in_array($folder, self::SUBSYSTEM_FOLDERS, true);
        $isNeoCart = mb_strtolower($extension) === 'neo';

        if ($isSubsystem || $isNeoCart) {
            return null;
        }

        $name = $file->getFilename();

        if ($extension !== '') {
            $name = mb_substr($name, 0, -mb_strlen('.' . $extension, '8bit'), '8bit');
        }

        return md5($name);
    }
}
