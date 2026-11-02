<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Disc;

/**
 * Finds files in the ISO 9660 volume of a track like rc_cd_find_file_sector
 * does: names are compared ignoring case, a ";version" suffix is accepted
 * and sub-folders are separated by backslashes.
 */
final class Iso9660
{
    /** The volume descriptor is this many sectors into the track. */
    private const int VOLUME_DESCRIPTOR_OFFSET = 16;

    private const int VOLUME_DESCRIPTOR_BYTES = 256;

    private const string PRIMARY_VOLUME_SIGNATURE = "\1CD001";

    private const string SEPARATOR = '\\';

    /**
     * The first sector and the size in bytes of the file, or null when there
     * is no such file (a file starting at sector 0 counts as missing too).
     *
     * @return array{sector: int, size: int}|null
     */
    public static function findFile(DiscTrack $track, string $path): ?array
    {
        $reader = self::openRoot($track);

        if (! $reader instanceof DirectoryReader) {
            return null;
        }

        $names = explode(self::SEPARATOR, str_starts_with($path, self::SEPARATOR) ? mb_substr($path, 1, null, '8bit') : $path);
        $fileName = array_pop($names);

        foreach ($names as $directoryName) {
            $directory = self::findRecord($reader, static fn (DirectoryRecord $record): bool => $record->isDirectoryNamed($directoryName));

            if (! $directory instanceof DirectoryRecord) {
                return null;
            }

            $reader->moveTo($directory->sector);
        }

        $file = self::findRecord($reader, static fn (DirectoryRecord $record): bool => $record->isFileNamed($fileName));

        if (! $file instanceof DirectoryRecord || $file->sector === 0) {
            return null;
        }

        return ['sector' => $file->sector, 'size' => $file->size];
    }

    private static function openRoot(DiscTrack $track): ?DirectoryReader
    {
        $descriptor = $track->readSector($track->firstSector() + self::VOLUME_DESCRIPTOR_OFFSET, self::VOLUME_DESCRIPTOR_BYTES);

        if (mb_strlen($descriptor, '8bit') < self::VOLUME_DESCRIPTOR_BYTES || ! str_starts_with($descriptor, self::PRIMARY_VOLUME_SIGNATURE)) {
            return null;
        }

        /** @var array{blockSize: int, sector: int, size: int} $root */
        $root = unpack('x128/v1blockSize/x28/V1sector/x4/V1size', $descriptor);

        return new DirectoryReader($track, $root['sector'] & 0xFFFFFF, $root['size'], $root['blockSize']);
    }

    /**
     * @param  callable(DirectoryRecord): bool  $matches
     */
    private static function findRecord(DirectoryReader $reader, callable $matches): ?DirectoryRecord
    {
        while (($record = $reader->next()) instanceof DirectoryRecord) {
            if ($matches($record)) {
                return $record;
            }
        }

        return null;
    }
}
