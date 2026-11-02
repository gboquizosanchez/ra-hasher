<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Hashers;

use Boquizo\RaHasher\Disc\Chd\ChdFile;
use Boquizo\RaHasher\Disc\Chd\ChdTrack;
use Boquizo\RaHasher\Disc\DiscTrack;
use Boquizo\RaHasher\Disc\Iso9660;
use Boquizo\RaHasher\Disc\SpecialTrack;
use Closure;
use HashContext;
use Override;
use RuntimeException;

/**
 * MD5 of the IP.BIN and the boot executable of a Dreamcast disc (rc_hash_dreamcast).
 * The IP.BIN is looked for in track 3 of a GD-ROM and in the first data track
 * of a MIL-CD; the executable in that track, or else in the last one.
 */
final readonly class DreamcastHasher extends DiscHasher
{
    private const string IP_BIN_MARKER = 'SEGA SEGAKATANA ';

    private const int IP_BIN_BYTES = 256;

    /** The data track of a GD-ROM that holds the TOC and the IP.BIN. */
    private const int GD_ROM_TRACK = 3;

    private const int BOOT_NAME_OFFSET = 96;

    private const int BOOT_NAME_MAX_LENGTH = 16;

    private const string BOOT_NAME_TERMINATORS = " \t\n\v\f\r";

    #[Override]
    protected function hashImage(ChdFile $chd): ?string
    {
        $track = $this->openGdRomTrack($chd);

        if ($track === null || $this->readIpBin($track) === null) {
            $track = ChdTrack::open($chd, SpecialTrack::FirstData);
        }

        $context = hash_init('md5');

        return $this->hashBoot($context, $track, fn (): DiscTrack => ChdTrack::open($chd, SpecialTrack::Last))
            ? hash_final($context)
            : null;
    }

    #[Override]
    protected function hashFiles(HashContext $context, DiscTrack $track): bool
    {
        return $this->hashBoot($context, $track, fn (): DiscTrack => $track);
    }

    /**
     * @param  Closure(): DiscTrack  $lastTrack  opens the track the executable is in when the first one does not hold it
     */
    private function hashBoot(HashContext $context, DiscTrack $track, Closure $lastTrack): bool
    {
        $ipBin = $this->readIpBin($track);

        if ($ipBin === null) {
            return false;
        }

        hash_update($context, $ipBin);

        $name = $this->bootName($ipBin);
        $executable = $name === '' ? null : Iso9660::findFile($track, $name);

        if ($executable === null) {
            return false;
        }

        if ($track->readSector($executable['sector'], 1) === '') {
            $track = $lastTrack();
        }

        return $this->hashFile($context, $track, $executable['sector'], $executable['size']);
    }

    private function openGdRomTrack(ChdFile $chd): ?ChdTrack
    {
        try {
            return ChdTrack::open($chd, self::GD_ROM_TRACK);
        } catch (RuntimeException) {
            return null;
        }
    }

    /** The first bytes of the track when they are an IP.BIN, null otherwise. */
    private function readIpBin(DiscTrack $track): ?string
    {
        $ipBin = $track->readSector($track->firstSector(), self::IP_BIN_BYTES);

        if (mb_strlen($ipBin, '8bit') < self::IP_BIN_BYTES || ! str_starts_with($ipBin, self::IP_BIN_MARKER)) {
            return null;
        }

        return $ipBin;
    }

    /** The name of the executable, up to the first blank or the end of the C string. */
    private function bootName(string $ipBin): string
    {
        $field = mb_substr($ipBin, self::BOOT_NAME_OFFSET, self::BOOT_NAME_MAX_LENGTH, '8bit');

        return explode("\0", mb_substr($field, 0, strcspn($field, self::BOOT_NAME_TERMINATORS), '8bit'), 2)[0];
    }
}
