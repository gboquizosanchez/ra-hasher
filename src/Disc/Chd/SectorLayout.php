<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Disc\Chd;

use Closure;
use RuntimeException;

/**
 * Where the user data lies in the frames of a CHD track: the bytes of
 * sector header to skip and the bytes of data after them. Track types that
 * do not tell are recognised from the start of sector 16, the ISO 9660
 * volume descriptor, like rc_hash_handle_chd_open_track of RAHasher does.
 */
final readonly class SectorLayout
{
    /** The bytes of sector 16 that tell the layout. */
    public const int SAMPLE_BYTES = 32;

    /** The bytes of a raw CD sector, without its subcode. */
    private const int RAW_SECTOR_BYTES = 2352;

    private const string ISO_9660_IDENTIFIER = 'CD001';

    private const string SYNC = "\x00\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\x00";

    public function __construct(
        public int $headerBytes,
        public int $dataBytes,
    ) {}

    /**
     * The whole raw sector, which is how the sample is read.
     */
    public static function raw(): self
    {
        return new self(0, self::RAW_SECTOR_BYTES);
    }

    /**
     * The layout of a track of the type (the TYPE of its CHD metadata).
     *
     * @param  Closure(): string  $sample  reads the first SAMPLE_BYTES raw bytes of sector 16 of the track, only for types other than MODE1_RAW, MODE1 and AUDIO
     */
    public static function detect(string $type, Closure $sample): self
    {
        return match ($type) {
            'MODE1_RAW' => new self(16, 2048),
            'MODE1' => new self(0, 2048),
            'AUDIO' => new self(0, self::RAW_SECTOR_BYTES),
            default => self::fromSample($sample()),
        };
    }

    /**
     * An ISO 9660 identifier after a mode 2 XA subheader (whose third byte
     * tells form 2 from form 1), after a sector header (whose last byte is the
     * mode) or at the start of the data, else a sync pattern, else 2048-byte
     * sectors without a header.
     */
    private static function fromSample(string $sample): self
    {
        if (mb_strlen($sample, '8bit') < self::SAMPLE_BYTES) {
            throw new RuntimeException('The CD track is too short to tell the layout of its sectors.');
        }

        $isMode1 = (ord($sample[15]) & 3) === 1;

        return match (true) {
            self::hasIdentifierAt($sample, 25) => new self(24, (ord($sample[18]) & 0x20) !== 0 ? 2324 : 2048),
            self::hasIdentifierAt($sample, 17) => new self(16, $isMode1 ? 2048 : 2336),
            self::hasIdentifierAt($sample, 1) => new self(0, 2048),
            str_starts_with($sample, self::SYNC) => new self(16, $isMode1 ? 2048 : 2336),
            default => new self(0, 2048),
        };
    }

    private static function hasIdentifierAt(string $sample, int $offset): bool
    {
        return mb_substr($sample, $offset, mb_strlen(self::ISO_9660_IDENTIFIER, '8bit'), '8bit') === self::ISO_9660_IDENTIFIER;
    }
}
