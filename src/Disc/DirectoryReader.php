<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Disc;

use RuntimeException;

/**
 * Walks the records of an ISO 9660 directory (rc_cd_find_next_file), one
 * sector at a time. Like rcheevos, it follows the size of the directory it
 * started from, also when it moves into a sub-directory. Where rcheevos would
 * go on with the stale bytes of a sector read short, it throws.
 */
final class DirectoryReader
{
    /** The bytes a record needs at least: the fixed fields and one name byte. */
    private const int MINIMUM_RECORD_SIZE = 34;

    private string $buffer = '';

    private ?int $position = null;

    public function __construct(
        private readonly DiscTrack $track,
        private int $sector,
        private int $remainingSize,
        private readonly int $blockSize,
    ) {}

    /** Starts over at the first sector of another directory. */
    public function moveTo(int $sector): void
    {
        $this->sector = $sector;
        $this->position = null;
    }

    public function next(): ?DirectoryRecord
    {
        if ($this->position === null && ! $this->load($this->sector)) {
            return null;
        }

        while (true) {
            $position = (int) $this->position;

            if ($position >= DiscTrack::SECTOR_SIZE - self::MINIMUM_RECORD_SIZE || $this->byteAt($position) === 0) {
                if ($this->remainingSize > $this->blockSize) {
                    $this->remainingSize -= $this->blockSize;

                    if ($this->load(++$this->sector)) {
                        continue;
                    }
                }

                return null;
            }

            $this->position = $position + $this->byteAt($position);

            if ($this->position > DiscTrack::SECTOR_SIZE) {
                continue;
            }

            return $this->recordAt($position);
        }
    }

    private function load(int $sector): bool
    {
        $data = $this->track->readSector($sector, DiscTrack::SECTOR_SIZE);

        if ($data === '') {
            return false;
        }

        if (mb_strlen($data, '8bit') < DiscTrack::SECTOR_SIZE) {
            throw new RuntimeException('The directory sector ' . $sector . ' is cut short.');
        }

        $this->buffer = $data;
        $this->position = 0;

        return true;
    }

    private function recordAt(int $position): DirectoryRecord
    {
        /** @var array{sector: int, size: int, nameLength: int} $fields */
        $fields = unpack('x2/V1sector/x4/V1size/x18/C1nameLength', $this->buffer, $position);

        return new DirectoryRecord(
            sector: $fields['sector'] & 0xFFFFFF,
            size: $fields['size'],
            nameLength: $fields['nameLength'],
            nameField: mb_substr($this->buffer, $position + 33, null, '8bit'),
        );
    }

    private function byteAt(int $position): int
    {
        return ord($this->buffer[$position]);
    }
}
