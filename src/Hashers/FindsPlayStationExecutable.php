<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Hashers;

use Boquizo\RaHasher\Disc\DiscTrack;
use Boquizo\RaHasher\Disc\Iso9660;

/**
 * Finds the boot executable of a PlayStation disc in SYSTEM.CNF, the way
 * rc_hash_find_playstation_executable does.
 */
trait FindsPlayStationExecutable
{
    /** SYSTEM.CNF is read up to this many bytes. */
    private const int SYSTEM_CONFIGURATION_BYTES = DiscTrack::SECTOR_SIZE - 1;

    /** The longest executable name rcheevos keeps (its buffer holds 64 bytes with the terminator). */
    private const int MAX_EXECUTABLE_NAME_LENGTH = 63;

    private const string WHITESPACE = " \t\n\v\f\r";

    /**
     * Looks in SYSTEM.CNF for the boot line and finds the executable it names.
     *
     * @return array{name: string, sector: int, size: int}|null
     */
    private function findBootExecutable(DiscTrack $track, string $bootKey, string $cdromPrefix): ?array
    {
        $configuration = Iso9660::findFile($track, 'SYSTEM.CNF');

        if ($configuration === null) {
            return null;
        }

        $text = $track->readSector($configuration['sector'], self::SYSTEM_CONFIGURATION_BYTES);
        $name = $this->bootExecutableName($text, $bootKey, $cdromPrefix);

        if ($name === null) {
            return null;
        }

        $executable = Iso9660::findFile($track, $name);

        return $executable === null ? null : ['name' => $name, ...$executable];
    }

    /**
     * The name after "BOOT =" (or the key given) without the CD-ROM prefix,
     * leading backslashes and ";version". It walks the bytes read exactly as
     * rcheevos walks its buffer: whitespace includes newlines, a line is
     * skipped up to its newline or a null byte, which the scan goes past, and
     * the end of the data counts as the terminating null byte.
     */
    private function bootExecutableName(string $text, string $bootKey, string $cdromPrefix): ?string
    {
        $length = mb_strlen($text, '8bit');
        $position = 0;

        while ($position < $length && $text[$position] !== "\0") {
            if ($this->hasAt($text, $position, $bootKey)) {
                $position = $this->skipWhitespace($text, $position + mb_strlen($bootKey, '8bit'));

                if ($position < $length && $text[$position] === '=') {
                    $position = $this->skipWhitespace($text, $position + 1);
                    $start = $position;

                    if ($this->hasAt($text, $position, $cdromPrefix)) {
                        $start += mb_strlen($cdromPrefix, '8bit');
                    }

                    while ($start < $length && $text[$start] === '\\') {
                        $start++;
                    }

                    $end = $start;

                    while ($end < $length && ! str_contains(self::WHITESPACE, $text[$end]) && $text[$end] !== ';') {
                        $end++;
                    }

                    $name = explode("\0", mb_substr($text, $start, $end - $start, '8bit'), 2)[0];

                    return mb_substr($name, 0, self::MAX_EXECUTABLE_NAME_LENGTH, '8bit');
                }
            }

            while ($position < $length && $text[$position] !== "\0" && $text[$position] !== "\n") {
                $position++;
            }

            $position++;
        }

        return null;
    }

    private function skipWhitespace(string $text, int $position): int
    {
        while ($position < mb_strlen($text, '8bit') && str_contains(self::WHITESPACE, $text[$position])) {
            $position++;
        }

        return $position;
    }

    private function hasAt(string $text, int $position, string $needle): bool
    {
        return mb_substr($text, $position, mb_strlen($needle, '8bit'), '8bit') === $needle;
    }
}
