<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Disc\Chd;

use Boquizo\RaHasher\Disc\DiscTrack;
use Boquizo\RaHasher\Disc\SpecialTrack;
use RuntimeException;
use SplFileInfo;

/**
 * A track of a CD (or DVD) stored in a CHD image, found and read like the
 * CHD cdreader of RAHasher (HashCHD.cpp) does.
 */
final readonly class ChdTrack implements DiscTrack
{
    /**
     * The metadata tags of CD tracks (CHT2), old CD tracks (CHTR) and GD-ROM
     * tracks (CHGD), in the order they are looked for, with a pattern for the
     * leading fields of their libchdr sscanf formats that are used.
     */
    private const array TRACK_METADATA_PATTERNS = [
        'CHT2' => '/^TRACK:(?<number>\d+) TYPE:(?<type>\S+) SUBTYPE:\S+ FRAMES:(?<frames>\d+) PREGAP:(?<pregap>\d+)/',
        'CHTR' => '/^TRACK:(?<number>\d+) TYPE:(?<type>\S+) SUBTYPE:\S+ FRAMES:(?<frames>\d+)/',
        'CHGD' => '/^TRACK:(?<number>\d+) TYPE:(?<type>\S+) SUBTYPE:\S+ FRAMES:(?<frames>\d+) PAD:\d+ PREGAP:(?<pregap>\d+)/',
    ];

    private const string DVD_METADATA_TAG = 'DVD ';

    private const string AUDIO_TYPE = 'AUDIO';

    /** The sector of the ISO 9660 volume descriptor, which tells the sector layout. */
    private const int VOLUME_DESCRIPTOR_SECTOR = 16;

    /** Every track takes a multiple of this many frames of the CHD image. */
    private const int TRACK_FRAME_ALIGNMENT = 4;

    /**
     * @param  positive-int  $framesPerHunk
     */
    private function __construct(
        private ChdFile $chd,
        private int $firstSector,
        private int $firstFrame,
        private int $frameCount,
        private int $framesPerHunk,
        private SectorLayout $layout,
    ) {}

    /**
     * Opens the numbered track or a special one. CHD images keep no sessions,
     * so the first track of the second session is taken to be track 2.
     */
    public static function open(SplFileInfo|string|ChdFile $source, int|SpecialTrack $track): self
    {
        $chd = $source instanceof ChdFile ? $source : ChdFile::open($source);
        $located = self::locate($chd, $track === SpecialTrack::FirstOfSecondSession ? 2 : $track);

        if ($located === null) {
            throw new RuntimeException('The CHD image has no ' . ($track instanceof SpecialTrack ? $track->name . ' track' : 'track ' . $track) . '.');
        }

        $framesPerHunk = intdiv($chd->hunkBytes(), $chd->unitBytes());

        if ($framesPerHunk < 1) {
            throw new RuntimeException('The CHD hunks of ' . $chd->hunkBytes() . ' bytes do not hold whole units.');
        }

        $rawTrack = new self($chd, $located['sectorOffset'], $located['frameOffset'], $located['frames'], $framesPerHunk, SectorLayout::raw());
        $layout = SectorLayout::detect(
            $located['type'],
            fn (): string => $rawTrack->readSector($located['sectorOffset'] + self::VOLUME_DESCRIPTOR_SECTOR, SectorLayout::SAMPLE_BYTES),
        );

        return new self($chd, $located['sectorOffset'], $located['frameOffset'], $located['frames'], $framesPerHunk, $layout);
    }

    /**
     * Sectors are numbered from the start of the disc, so a sector before the
     * track reads nothing; one sector past its last frame is still read, like
     * RAHasher does. A read carries on into the following frames, past the
     * end of the track if need be, and stops short at the end of the image.
     *
     * RAHasher moves to the next hunk once the offset is past the hunk size,
     * which only differs from reaching it for sectors without a header, where
     * it would read past the hunk.
     *
     * @param  int<0, max>  $bytes
     */
    public function readSector(int $sector, int $bytes): string
    {
        $frame = $sector - $this->firstSector;

        if ($frame < 0 || $frame > $this->frameCount) {
            return '';
        }

        $chdFrame = $frame + $this->firstFrame;
        $hunk = intdiv($chdFrame, $this->framesPerHunk);
        $offset = ($chdFrame % $this->framesPerHunk) * $this->chd->unitBytes() + $this->layout->headerBytes;
        $data = '';

        while ($hunk < $this->chd->hunkCount()) {
            $hunkData = $this->chd->readHunk($hunk);

            if ($bytes <= $this->layout->dataBytes) {
                return $data . mb_substr($hunkData, $offset, $bytes, '8bit');
            }

            $data .= mb_substr($hunkData, $offset, $this->layout->dataBytes, '8bit');
            $bytes -= $this->layout->dataBytes;
            $offset += $this->chd->unitBytes();

            if ($offset >= $this->chd->hunkBytes()) {
                $offset = $this->layout->headerBytes;
                $hunk++;
            }
        }

        return $data;
    }

    public function firstSector(): int
    {
        return $this->firstSector;
    }

    /**
     * The track and its position, as rc_hash_find_chd_track finds it. Track
     * numbers are matched before audio tracks are skipped.
     *
     * For the largest track RAHasher re-reads only the metadata of the track
     * it found, keeping the position of the last track: so the type and
     * frames of the largest data track (or of the first track when all are
     * audio) come with the sectors and frames of the last track. Only PC-FX
     * discs look for the largest track, and then for track 2 when it fails.
     *
     * @return array{number: int, type: string, frames: int, sectorOffset: int, frameOffset: int}|null
     */
    private static function locate(ChdFile $chd, int|SpecialTrack $track): ?array
    {
        $tracks = self::positionedTracks($chd);

        if ($tracks === []) {
            return null;
        }

        $largest = $tracks[0];
        $largestFrames = 0;

        foreach ($tracks as $candidate) {
            if ($candidate['number'] === $track) {
                return $candidate;
            }

            if ($candidate['type'] === self::AUDIO_TYPE) {
                continue;
            }

            if ($track === SpecialTrack::FirstData) {
                return $candidate;
            }

            if ($candidate['frames'] > $largestFrames) {
                $largestFrames = $candidate['frames'];
                $largest = $candidate;
            }
        }

        $last = $tracks[array_key_last($tracks)];

        return match ($track) {
            SpecialTrack::Last => $last,
            SpecialTrack::Largest => ['type' => $largest['type'], 'frames' => $largest['frames']] + $last,
            default => null,
        };
    }

    /**
     * Every track of the image with the first sector and CHD frame of its
     * data. Sectors count the frames of the tracks before; CHD frames also
     * count their pregaps and the padding of every track to a multiple of 4
     * frames.
     *
     * @return list<array{number: int, type: string, frames: int, sectorOffset: int, frameOffset: int}>
     */
    private static function positionedTracks(ChdFile $chd): array
    {
        $tracks = [];
        $sectorOffset = 0;
        $frameOffset = 0;

        for ($index = 0; ($metadata = self::trackMetadata($chd, $index)) !== null; $index++) {
            $frameOffset += $metadata['pregap'];
            $tracks[] = ['number' => $metadata['number'], 'type' => $metadata['type'], 'frames' => $metadata['frames'], 'sectorOffset' => $sectorOffset, 'frameOffset' => $frameOffset];
            $sectorOffset += $metadata['frames'];
            $frameOffset += intdiv($metadata['frames'] + self::TRACK_FRAME_ALIGNMENT - 1, self::TRACK_FRAME_ALIGNMENT) * self::TRACK_FRAME_ALIGNMENT;
        }

        return $tracks;
    }

    /**
     * The index-th track metadata of the first tag that has one. An image
     * without any is taken for a DVD, a single track of 2048-byte sectors,
     * when it has DVD metadata.
     *
     * @return array{number: int, type: string, frames: int, pregap: int}|null
     */
    private static function trackMetadata(ChdFile $chd, int $index): ?array
    {
        foreach (self::TRACK_METADATA_PATTERNS as $tag => $pattern) {
            $metadata = $chd->metadata($tag, $index);

            if ($metadata !== null) {
                return self::parseTrackMetadata($metadata, $pattern);
            }
        }

        if ($index === 0 && $chd->metadata(self::DVD_METADATA_TAG, 0) !== null) {
            return ['number' => 1, 'type' => 'MODE1', 'frames' => $chd->unitCount(), 'pregap' => 0];
        }

        return null;
    }

    /**
     * Only the leading fields that are used must be there, as sscanf stops
     * at the first one that does not match.
     *
     * @return array{number: int, type: string, frames: int, pregap: int}
     */
    private static function parseTrackMetadata(string $metadata, string $pattern): array
    {
        if (preg_match($pattern, $metadata, $fields) !== 1) {
            throw new RuntimeException('The CHD track metadata "' . $metadata . '" cannot be read.');
        }

        return [
            'number' => (int) $fields['number'],
            'type' => $fields['type'],
            'frames' => (int) $fields['frames'],
            'pregap' => (int) ($fields['pregap'] ?? 0),
        ];
    }
}
