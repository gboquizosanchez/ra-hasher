<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Hashers;

use Boquizo\RaHasher\Disc\DiscTrack;
use HashContext;
use Override;

/**
 * MD5 of the volume header and the LaunchMe file of a 3DO disc, found in the
 * directories of its Opera file system (rc_hash_3do).
 */
final readonly class ThreeDoHasher extends DiscHasher
{
    private const string OPERA_IDENTIFIER = "\x01ZZZZZ\x01";

    private const int HEADER_BYTES = 132;

    private const string LAUNCH_FILE = 'LaunchMe';

    private const int FILE_TYPE = 0x02;

    private const int ENTRY_SIZE = 0x48;

    private const int NAME_OFFSET = 0x20;

    private const int NO_NEXT_BLOCK = 0xFFFF;

    #[Override]
    protected function hashFiles(HashContext $context, DiscTrack $track): bool
    {
        $header = $track->readSector(0, self::HEADER_BYTES);

        if (mb_strlen($header, '8bit') < self::HEADER_BYTES || ! str_starts_with($header, self::OPERA_IDENTIFIER)) {
            return false;
        }

        hash_update($context, $header);

        $blockSize = $this->number($header, 0x4D, 3);
        $blockLocation = $this->number($header, 0x65, 3) * $blockSize;
        $sector = intdiv($blockLocation, DiscTrack::SECTOR_SIZE);
        $size = 0;
        $visited = [];

        do {
            if (isset($visited[$sector])) {
                return false;
            }

            $visited[$sector] = true;
            $buffer = $track->readSector($sector, DiscTrack::SECTOR_SIZE);

            if (mb_strlen($buffer, '8bit') < DiscTrack::SECTOR_SIZE) {
                return false;
            }

            $offset = $this->number($buffer, 0x12, 2);
            $stop = $this->number($buffer, 0x0D, 3);

            while ($offset < $stop) {
                if ($offset + self::ENTRY_SIZE > DiscTrack::SECTOR_SIZE) {
                    return false;
                }

                if (ord($buffer[$offset + 0x03]) === self::FILE_TYPE && $this->isLaunchFile($buffer, $offset)) {
                    $blockSize = $this->number($buffer, $offset + 0x0D, 3);
                    $blockLocation = $this->number($buffer, $offset + 0x45, 3) * $blockSize;
                    $size = $this->number($buffer, $offset + 0x11, 3);

                    break;
                }

                $offset += self::ENTRY_SIZE + ord($buffer[$offset + 0x43]) * 4;
            }

            if ($size !== 0) {
                break;
            }

            $next = $this->number($buffer, 0x02, 2);

            if ($next === self::NO_NEXT_BLOCK) {
                return false;
            }

            $sector = intdiv($blockLocation + $next * $blockSize, DiscTrack::SECTOR_SIZE);
        } while (true);

        return $this->hashSectors($context, $track, intdiv($blockLocation, DiscTrack::SECTOR_SIZE), $size);
    }

    private function isLaunchFile(string $buffer, int $offset): bool
    {
        $name = mb_substr($buffer, $offset + self::NAME_OFFSET, self::ENTRY_SIZE - self::NAME_OFFSET, '8bit');

        return strcasecmp(explode("\0", $name, 2)[0], self::LAUNCH_FILE) === 0;
    }

    /**
     * A big endian number of $bytes bytes.
     *
     * @return int<0, max>
     */
    private function number(string $data, int $offset, int $bytes): int
    {
        $number = 0;

        for ($index = 0; $index < $bytes; $index++) {
            $number = $number * 256 + ord($data[$offset + $index]);
        }

        return $number;
    }
}
