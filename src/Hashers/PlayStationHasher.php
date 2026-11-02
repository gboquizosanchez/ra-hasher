<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Hashers;

use Boquizo\RaHasher\Disc\DiscTrack;
use Boquizo\RaHasher\Disc\Iso9660;
use HashContext;
use Override;

/**
 * MD5 of the name and the contents of the boot executable of a PlayStation
 * disc, named by the BOOT line of SYSTEM.CNF or else PSX.EXE (rc_hash_psx).
 * A PS-X EXE header tells the size of the executable.
 */
final readonly class PlayStationHasher extends DiscHasher
{
    use FindsPlayStationExecutable;

    private const string FALLBACK_EXECUTABLE = 'PSX.EXE';

    private const string EXECUTABLE_SIGNATURE = 'PS-X EX';

    private const int HEADER_BYTES = 32;

    /** The header comes before the executable and is not part of its size. */
    private const int HEADER_SIZE = 2048;

    #[Override]
    protected function hashFiles(HashContext $context, DiscTrack $track): bool
    {
        $executable = $this->findBootExecutable($track, 'BOOT', 'cdrom:');

        if ($executable === null) {
            $file = Iso9660::findFile($track, self::FALLBACK_EXECUTABLE);
            $executable = $file === null ? null : ['name' => self::FALLBACK_EXECUTABLE, ...$file];
        }

        if ($executable === null) {
            return false;
        }

        $header = $track->readSector($executable['sector'], self::HEADER_BYTES);

        if (mb_strlen($header, '8bit') < self::HEADER_BYTES) {
            return false;
        }

        $size = $executable['size'];

        if (str_starts_with($header, self::EXECUTABLE_SIGNATURE)) {
            /** @var array{size: int} $declared */
            $declared = unpack('x28/V1size', $header);
            $size = ($declared['size'] + self::HEADER_SIZE) & 0xFFFFFFFF;
        }

        hash_update($context, $executable['name']);

        return $this->hashFile($context, $track, $executable['sector'], $size);
    }
}
