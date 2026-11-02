<?php

declare(strict_types=1);

use Boquizo\RaHasher\Disc\Chd\ChdMapEntry;
use Boquizo\RaHasher\Disc\Chd\Crc16;
use Boquizo\RaHasher\Disc\DiscTrack;
use Boquizo\RaHasher\Disc\Nintendo\NintendoDisc;
use Boquizo\RaHasher\Disc\Rvz\LaggedFibonacciGenerator;

/**
 * Packs values into a binary string, most significant bit first, padding
 * the last byte with zeros.
 *
 * @param  list<array{int, int}>  $fields  each value with its number of bits
 */
function chdBits(array $fields): string
{
    $bits = '';

    foreach ($fields as [$value, $bitCount]) {
        $bits .= $bitCount === 0 ? '' : mb_str_pad(decbin($value), $bitCount, '0', STR_PAD_LEFT, '8bit');
    }

    $bits = mb_str_pad($bits, (int) ceil(mb_strlen($bits, '8bit') / 8) * 8, '0', STR_PAD_RIGHT, '8bit');

    return implode('', array_map(fn (string $byte): string => chr((int) bindec($byte)), mb_str_split($bits, 8, '8bit')));
}

const CHD_MAP_FIRST_OFFSET = 0x400;

const CHD_MAP_HUNK_BYTES = 4096;

/**
 * A compressed map whose Huffman tree gives all 16 symbols a 4-bit code,
 * the symbol itself, so the first pass is written as plain nibbles.
 *
 * @param  list<int>  $types  the symbols of the first pass
 * @param  list<array{int, int}>  $fields  the fields of the second pass, with their bits
 * @param  list<array{int, int, int, int}>  $records  the type, length, offset and CRC of every hunk, for the map CRC
 */
function compressedChdMap(array $types, array $fields, array $records = [], int $lengthBits = 16, int $selfBits = 8, int $parentBits = 0, ?int $crc = null): string
{
    $bitstream = chdBits([[1, 4], [4, 4], [13, 4], ...array_map(fn (int $type): array => [$type, 4], $types), ...$fields]);
    $packedRecords = implode('', array_map(fn (array $record): string => pack('NJ', ($record[0] << 24) | $record[1], ($record[2] << 16) | $record[3]), $records));

    return pack('NnNnCCCC', mb_strlen($bitstream, '8bit'), 0, CHD_MAP_FIRST_OFFSET, $crc ?? Crc16::compute($packedRecords), $lengthBits, $selfBits, $parentBits, 0) . $bitstream;
}

const SYNTHETIC_CHD_HUNK_BYTES = 300;

function chdFixturePath(string $name): string
{
    return __DIR__ . '/Fixtures/Chd/' . $name . '.chd';
}

/**
 * A CHD v5 image with a compressed map: the map right after the header,
 * the hunk data from CHD_MAP_FIRST_OFFSET on and the metadata after it.
 *
 * @param  list<array{int, string, int}>  $hunks  the type, stored data and CRC of every hunk; for SELF hunks, the source hunk in place of the CRC
 * @param  list<string>  $compressors  the codecs of hunk types 0 to 3, none for the missing ones
 * @param  list<array{string, string}>  $metadata  the tag and data of every metadata entry
 * @param  int|null  $unitBytes  the bytes of a unit, the hunk size when null
 */
function syntheticChd(array $hunks, array $compressors = ['zlib', 'lzma', 'cdzl', 'cdfl'], array $metadata = [], int $hunkBytes = SYNTHETIC_CHD_HUNK_BYTES, ?int $unitBytes = null): string
{
    $types = [];
    $fields = [];
    $records = [];
    $hunkData = '';

    foreach ($hunks as [$type, $stored, $crcOrSource]) {
        $types[] = $type;

        if ($type === ChdMapEntry::SELF) {
            $fields[] = [$crcOrSource, 8];
            $records[] = [$type, 0, $crcOrSource, 0];

            continue;
        }

        $length = $type === ChdMapEntry::UNCOMPRESSED ? $hunkBytes : mb_strlen($stored, '8bit');
        array_push($fields, ...($type === ChdMapEntry::UNCOMPRESSED ? [[$crcOrSource, 16]] : [[$length, 16], [$crcOrSource, 16]]));
        $records[] = [$type, $length, CHD_MAP_FIRST_OFFSET + mb_strlen($hunkData, '8bit'), $crcOrSource];
        $hunkData .= $stored;
    }

    $metadataOffset = $metadata === [] ? 0 : CHD_MAP_FIRST_OFFSET + mb_strlen($hunkData, '8bit');
    $metadataEntries = '';

    foreach ($metadata as $index => [$tag, $data]) {
        $next = $index === count($metadata) - 1 ? 0 : $metadataOffset + mb_strlen($metadataEntries, '8bit') + 16 + mb_strlen($data, '8bit');
        $metadataEntries .= $tag . pack('NJ', mb_strlen($data, '8bit'), $next) . $data;
    }

    $header = 'MComprHD' . pack('NN', 124, 5)
        . implode('', array_map(fn (string $compressor): string => mb_str_pad($compressor, 4, "\0", STR_PAD_RIGHT, '8bit'), array_pad($compressors, 4, '')))
        . pack('JJJNN', count($hunks) * $hunkBytes, 124, $metadataOffset, $hunkBytes, $unitBytes ?? $hunkBytes)
        . str_repeat("\0", 60);

    return mb_str_pad($header . compressedChdMap($types, $fields, $records), CHD_MAP_FIRST_OFFSET, "\0", STR_PAD_RIGHT, '8bit') . $hunkData . $metadataEntries;
}

function chdOnDisk(string $directory, string $image): string
{
    $path = $directory . DIRECTORY_SEPARATOR . 'image.chd';
    file_put_contents($path, $image);

    return $path;
}

/**
 * Overwrites the bytes of an image at the offset.
 */
function patchedChd(string $image, int $offset, string $bytes): string
{
    return mb_substr($image, 0, $offset, '8bit') . $bytes . mb_substr($image, $offset + mb_strlen($bytes, '8bit'), null, '8bit');
}

/**
 * A track held in memory, whose sectors are strings of 2048 bytes.
 *
 * @param  array<int, string>  $sectors  by absolute sector number
 */
function fakeDiscTrack(array $sectors, int $firstSector = 0): DiscTrack
{
    return new readonly class($sectors, $firstSector) implements DiscTrack
    {
        /** @param array<int, string> $sectors */
        public function __construct(private array $sectors, private int $firstSector) {}

        public function readSector(int $sector, int $bytes): string
        {
            $data = '';

            while (mb_strlen($data, '8bit') < $bytes && isset($this->sectors[$sector])) {
                $data .= mb_substr($this->sectors[$sector++], 0, $bytes - mb_strlen($data, '8bit'), '8bit');
            }

            return $data;
        }

        public function firstSector(): int
        {
            return $this->firstSector;
        }
    };
}

/**
 * A directory record: the sector (little endian at byte 2), the size (at
 * byte 10), the flags (at byte 25) and the name (at byte 33, its length at 32).
 */
function isoRecord(string $name, int $sector, int $size, bool $isDirectory = false): string
{
    $length = 33 + mb_strlen($name, '8bit');
    $length += $length % 2;

    $record = mb_str_pad(chr($length) . "\0" . pack('V', $sector), 10, "\0", STR_PAD_RIGHT, '8bit') . pack('V', $size);
    $record = mb_str_pad($record, 25, "\0", STR_PAD_RIGHT, '8bit') . chr($isDirectory ? 2 : 0);

    return mb_str_pad($record . "\0\0\0\0\0\0" . chr(mb_strlen($name, '8bit')) . $name, $length, "\0", STR_PAD_RIGHT, '8bit');
}

/**
 * The sectors of a directory: its records, none of them crossing the end
 * of a sector.
 *
 * @param  list<string>  $records
 * @return list<string>
 */
function isoDirectorySectors(array $records): array
{
    $sectors = [''];

    foreach ([isoRecord("\0", 0, 0, true), isoRecord("\1", 0, 0, true), ...$records] as $record) {
        if (mb_strlen($sectors[array_key_last($sectors)], '8bit') + mb_strlen($record, '8bit') > 2048) {
            $sectors[] = '';
        }

        $sectors[array_key_last($sectors)] .= $record;
    }

    return array_map(fn (string $sector): string => mb_str_pad($sector, 2048, "\0", STR_PAD_RIGHT, '8bit'), $sectors);
}

/**
 * Writes a directory and what it holds from $next on, files as strings and
 * sub-directories as arrays.
 *
 * @param  array<string, string|array<string, mixed>>  $entries
 * @param  array<int, string>  $sectors
 * @return array{int, int} the sector and the size of the directory
 */
function isoWriteDirectory(array $entries, array &$sectors, int &$next): array
{
    $directorySector = $next;
    $next += count(isoDirectorySectors(array_map(fn (string $name): string => isoRecord($name, 0, 0), array_keys($entries))));
    $records = [];

    foreach ($entries as $name => $content) {
        if (is_array($content)) {
            [$sector, $size] = isoWriteDirectory($content, $sectors, $next);
            $records[] = isoRecord((string) $name, $sector, $size, true);

            continue;
        }

        $sector = $next;
        foreach (mb_str_split($content === '' ? "\0" : $content, 2048, '8bit') as $chunk) {
            $sectors[$next++] = mb_str_pad($chunk, 2048, "\0", STR_PAD_RIGHT, '8bit');
        }
        $records[] = isoRecord((string) $name, $sector, mb_strlen($content, '8bit'));
    }

    foreach (isoDirectorySectors($records) as $index => $data) {
        $sectors[$directorySector + $index] = $data;
    }

    return [$directorySector, count(isoDirectorySectors($records)) * 2048];
}

/**
 * A track with a minimal ISO 9660 volume: the volume descriptor 16 sectors
 * into the track and the files from sector 20 on, where the entries are the
 * names with their contents, or sub-directories as arrays.
 *
 * @param  array<string, string|array<string, mixed>>  $entries
 */
function isoDisc(array $entries, int $firstSector = 0): DiscTrack
{
    $sectors = [];
    $next = $firstSector + 20;
    [$sector, $size] = isoWriteDirectory($entries, $sectors, $next);

    $sectors[$firstSector + 16] = isoVolumeDescriptor($sector, $size);

    return fakeDiscTrack($sectors, $firstSector);
}

/**
 * The primary volume descriptor, with the block size and the root directory.
 */
function isoVolumeDescriptor(int $rootSector, int $rootSize): string
{
    $descriptor = mb_str_pad("\1CD001\1", 128, "\0", STR_PAD_RIGHT, '8bit') . pack('v', 2048);
    $descriptor = mb_str_pad($descriptor, 156, "\0", STR_PAD_RIGHT, '8bit') . isoRecord('', $rootSector, $rootSize, true);

    return mb_str_pad($descriptor, 2048, "\0", STR_PAD_RIGHT, '8bit');
}

/**
 * The track without the sectors from $firstMissing on, or with zeros in
 * place of them when $filler is true.
 */
function trackFrom(DiscTrack $track, int $firstMissing, bool $filler = false): DiscTrack
{
    return new readonly class($track, $firstMissing, $filler) implements DiscTrack
    {
        public function __construct(private DiscTrack $track, private int $firstMissing, private bool $filler) {}

        public function readSector(int $sector, int $bytes): string
        {
            if ($sector < $this->firstMissing) {
                return $this->track->readSector($sector, $bytes);
            }

            return $this->filler ? str_repeat("\0", min($bytes, 2048)) : '';
        }

        public function firstSector(): int
        {
            return $this->track->firstSector();
        }
    };
}

/**
 * The track with the data in place of one of its sectors.
 */
function trackWithSector(DiscTrack $track, int $sector, string $data): DiscTrack
{
    return new readonly class($track, $sector, $data) implements DiscTrack
    {
        public function __construct(private DiscTrack $track, private int $sector, private string $data) {}

        public function readSector(int $sector, int $bytes): string
        {
            return $sector === $this->sector ? mb_substr($this->data, 0, $bytes, '8bit') : $this->track->readSector($sector, $bytes);
        }

        public function firstSector(): int
        {
            return $this->track->firstSector();
        }
    };
}

/**
 * A CD image on disk whose tracks are of raw mode 1 sectors (data) or silence (audio).
 * The sectors are the 2048 bytes of the data sectors by absolute sector number.
 *
 * @param  list<array{string, int}>  $tracks  the type (MODE1_RAW or AUDIO) and the frames of every track, in multiples of 4
 * @param  array<int, string>  $sectors
 */
function cdImageOnDisk(string $directory, array $tracks, array $sectors): string
{
    $frames = '';
    $metadata = [];

    foreach ($tracks as $index => [$type, $count]) {
        $metadata[] = ['CHT2', 'TRACK:' . ($index + 1) . ' TYPE:' . $type . ' SUBTYPE:NONE FRAMES:' . $count . ' PREGAP:0 PGTYPE:MODE1 PGSUB:NONE POSTGAP:0' . "\0"];
    }

    for ($sector = 0; $sector < array_sum(array_column($tracks, 1)); $sector++) {
        $frames .= str_repeat("\0", 16) . mb_str_pad($sectors[$sector] ?? '', 2048, "\0", STR_PAD_RIGHT, '8bit') . str_repeat("\0", 384);
    }

    $hunks = array_map(
        fn (string $hunk): array => [ChdMapEntry::UNCOMPRESSED, $hunk, Crc16::compute($hunk)],
        mb_str_split($frames, 2448, '8bit'),
    );

    return chdOnDisk($directory, syntheticChd($hunks, metadata: $metadata, hunkBytes: 2448));
}

/** The 128 bytes after the first sector: the system identifier (at 32), the title (106), the program sector (0) and its sector count (3). */
function pcEngineHeader(string $title, int $sector, int $count): string
{
    $header = mb_substr(pack('N', $sector), 1, null, '8bit') . chr($count);
    $header = mb_str_pad($header, 32, "\0", STR_PAD_RIGHT, '8bit') . 'PC Engine CD-ROM SYSTEM';

    return mb_str_pad($header, 106, "\0", STR_PAD_RIGHT, '8bit') . mb_str_pad($title, 22, ' ', STR_PAD_RIGHT, '8bit');
}

/** Deterministic bytes that do not compress. */
function noiseBytes(string $label, int $bytes): string
{
    $noise = '';

    for ($block = 0; mb_strlen($noise, '8bit') < $bytes; $block++) {
        $noise .= hash('sha256', $label . $block, true);
    }

    return mb_substr($noise, 0, $bytes, '8bit');
}

const GAME_CUBE_ISO_BYTES = 0x88000;

/** The disc header and apploader, the bytes rcheevos hashes before the DOL. */
const GAME_CUBE_HEADER_BYTES = 0x2440 + 0x20 + 0x1000 + 0x200;

/**
 * The offset and size of every DOL segment of the synthetic disc, which
 * rcheevos reads from the start of the disc. Two of them are empty and one
 * of those points past the end of the disc; one spans the first 128 KiB
 * group boundary and one lies in padding.
 *
 * @var list<array{int, int}>
 */
const GAME_CUBE_DOL_SEGMENTS = [
    [0x4100, 0x600],
    [0x7FFFFFF0, 0],
    [0x1F800, 0x1000],
    [0, 0], [0, 0], [0, 0], [0, 0],
    [0x67F00, 0x1000],
    [0x80100, 0x100],
    [0, 0], [0, 0], [0, 0], [0, 0], [0, 0], [0, 0], [0, 0], [0, 0], [0, 0],
];

/**
 * A GameCube disc image of 544 KiB: the header and apploader, a DOL at
 * 0x4000 with its segments, zeros up to 0x60000, four 32 KiB blocks of
 * padding (each one after 0x22 zero bytes, so the generator starts
 * mid-word) and 32 KiB of data that does not compress at the end.
 */
function syntheticGameCubeIso(): string
{
    $iso = str_repeat("\0", GAME_CUBE_ISO_BYTES);
    $iso = substr_replace($iso, 'GTSE01', 0, 6);
    $iso = substr_replace($iso, "\xC2\x33\x9F\x3D", 0x1C, 4);
    $iso = substr_replace($iso, 'Synthetic disc', 0x20, 14);
    $iso = substr_replace($iso, pack('N', 0x4000), 0x420, 4);
    $iso = substr_replace($iso, noiseBytes('apploader', 0x1220), 0x2440, 0x1220);
    $iso = substr_replace($iso, pack('NN', 0x1000, 0x200), 0x2454, 8);

    $offsets = array_column(GAME_CUBE_DOL_SEGMENTS, 0);
    $sizes = array_column(GAME_CUBE_DOL_SEGMENTS, 1);
    $loadAddresses = array_map(static fn (int $segment): int => 0x80003100 + $segment * 0x1000, range(0, 17));
    $iso = substr_replace($iso, pack('N*', ...$offsets, ...$loadAddresses, ...$sizes), 0x4000, 0xD8);
    $iso = substr_replace($iso, noiseBytes('text0', 0x600), 0x4100, 0x600);
    $iso = substr_replace($iso, noiseBytes('text2', 0x1000), 0x1F800, 0x1000);

    for ($block = 0; $block < 4; $block++) {
        $padding = LaggedFibonacciGenerator::generate(noiseBytes('seed' . $block, LaggedFibonacciGenerator::SEED_BYTES), 0x22, 0x8000 - 0x22);
        $iso = substr_replace($iso, $padding, 0x60000 + $block * 0x8000 + 0x22, 0x8000 - 0x22);
    }

    return substr_replace($iso, noiseBytes('tail', 0x8000), 0x80000, 0x8000);
}

/** The hash RAHasher gives the synthetic disc. */
const SYNTHETIC_GAME_CUBE_HASH = 'cd4820f6c7133352aa7a1af22055e406';

/** The hash of the synthetic disc, computed like rcheevos does. */
function syntheticGameCubeHash(string $iso): string
{
    $hashed = mb_substr($iso, 0, GAME_CUBE_HEADER_BYTES, '8bit');

    foreach (GAME_CUBE_DOL_SEGMENTS as [$offset, $size]) {
        $hashed .= $size === 0 ? '' : mb_substr($iso, $offset, $size, '8bit');
    }

    return md5($hashed);
}

function rvzFixturePath(string $name): string
{
    return __DIR__ . '/Fixtures/Rvz/' . $name . '.rvz';
}

/** The bytes of the synthetic encrypted Wii disc image. */
const WII_DISC_BYTES = 0x90000;

/** Where the TMD of every synthetic Wii partition starts, from the partition. */
const WII_TMD_OFFSET = 0x2C0;

/**
 * The partitions of the synthetic Wii disc: where each one starts, its type,
 * the TMD size field and the data offset and size it declares (the data is
 * read at that offset of the disc, like rcheevos does).
 *
 * @var list<array{offset: int, type: int, tmd: int, data: int, size: int}>
 */
const WII_PARTITIONS = [
    ['offset' => 0x50000, 'type' => 1, 'tmd' => 0x200, 'data' => 0x8000, 'size' => 0x8000],
    ['offset' => 0x60000, 'type' => 0, 'tmd' => 0x10000, 'data' => 0x20000, 'size' => 0x1C000],
    ['offset' => 0x70000, 'type' => 2, 'tmd' => 0x208, 'data' => 0x80000, 'size' => 0x10000],
];

/**
 * Writes the fields of a Wii partition header: the TMD size and offset and
 * the data offset and size, all but the TMD size divided by 4.
 */
function wiiPartitionHeader(string $iso, int $offset, int $tmdSize, int $tmdOffset, int $dataOffset, int $dataSize): string
{
    $iso = substr_replace($iso, pack('NN', $tmdSize, $tmdOffset >> 2), $offset + 0x2A4, 8);

    return substr_replace($iso, pack('NN', $dataOffset >> 2, $dataSize >> 2), $offset + 0x2B8, 8);
}

/**
 * The start of a Wii disc image: a header with the Wii magic word and the
 * encryption flag, and a region code.
 */
function wiiDiscStart(int $bytes, bool $encrypted): string
{
    $iso = str_repeat("\0", $bytes);
    $iso = substr_replace($iso, noiseBytes('wii header', 0x80), 0, 0x80);
    $iso = substr_replace($iso, 'RSYE01', 0, 6);
    $iso = substr_replace($iso, "\x5D\x1C\x9E\xA3", 0x18, 4);
    $iso = substr_replace($iso, $encrypted ? "\0" : "\1", 0x61, 1);

    return substr_replace($iso, noiseBytes('region', 0x20), 0x4E000, 0x20);
}

/**
 * An encrypted Wii disc image of 576 KiB with an update partition (skipped),
 * a game partition whose TMD size is past the 0x7C00 clamp and whose data
 * ends mid-cluster, and a channel partition listed in the third group. The
 * empty second group points past the end of the disc.
 */
function syntheticWiiDisc(): string
{
    $iso = wiiDiscStart(WII_DISC_BYTES, true);
    $iso = substr_replace($iso, pack('N8', 2, 0x40020 >> 2, 0, 0x7FFFFFFF, 1, 0x40030 >> 2, 0, 0), 0x40000, 32);

    foreach (WII_PARTITIONS as $index => $partition) {
        $entry = $index < 2 ? 0x40020 + $index * 8 : 0x40030;
        $iso = substr_replace($iso, pack('NN', $partition['offset'] >> 2, $partition['type']), $entry, 8);
        $iso = wiiPartitionHeader($iso, $partition['offset'], $partition['tmd'], WII_TMD_OFFSET, $partition['data'], $partition['size']);
        $iso = substr_replace($iso, noiseBytes('tmd' . $index, 0x7C00), $partition['offset'] + WII_TMD_OFFSET, 0x7C00);
    }

    $iso = substr_replace($iso, noiseBytes('update data', 0x8000), 0x8000, 0x8000);
    $iso = substr_replace($iso, noiseBytes('game data', 0x20000), 0x20000, 0x20000);

    return substr_replace($iso, noiseBytes('channel data', 0x10000), 0x80000, 0x10000);
}

/** The hash of the synthetic encrypted Wii disc, computed like rcheevos does. */
function syntheticWiiHash(string $iso): string
{
    $hashed = mb_substr($iso, 0, 0x80, '8bit') . mb_substr($iso, 0x4E000, 4, '8bit');

    foreach (WII_PARTITIONS as $partition) {
        if ($partition['type'] === 1) {
            continue;
        }

        $hashed .= mb_substr($iso, $partition['offset'] + WII_TMD_OFFSET, min($partition['tmd'], 0x7C00), '8bit');

        for ($cluster = 0; $cluster < intdiv($partition['size'], 0x8000); $cluster++) {
            $hashed .= mb_substr($iso, $partition['data'] + $cluster * 0x8000 + 0x400, 0x7C00, '8bit');
        }
    }

    return md5($hashed);
}

/**
 * The DOL segments of the synthetic decrypted Wii partition, from the
 * partition data, which stores them divided by 4.
 *
 * @var list<array{int, int}>
 */
const DECRYPTED_WII_DOL_SEGMENTS = [[0x5000, 0x800], [0x6000, 0x1000], [0x8000, 0x400]];

/**
 * A decrypted Wii disc image of 384 KiB with a game partition whose data
 * (read at 0x20000 like rcheevos does) holds a header, an apploader and a
 * DOL with three segments, its offsets and sizes divided by 4.
 */
function syntheticDecryptedWiiDisc(): string
{
    $iso = wiiDiscStart(0x60000, false);
    $iso = substr_replace($iso, pack('N2', 1, 0x40020 >> 2), 0x40000, 8);
    $iso = substr_replace($iso, pack('NN', 0x50000 >> 2, 0), 0x40020, 8);
    $iso = wiiPartitionHeader($iso, 0x50000, 0x100, WII_TMD_OFFSET, 0x20000, 0x10000);
    $iso = substr_replace($iso, noiseBytes('decrypted tmd', 0x100), 0x50000 + WII_TMD_OFFSET, 0x100);

    $iso = substr_replace($iso, noiseBytes('decrypted header', 0x3660), 0x20000, 0x3660);
    $iso = substr_replace($iso, pack('N', 0x4000 >> 2), 0x20420, 4);
    $iso = substr_replace($iso, pack('NN', 0x1000, 0x200), 0x22454, 8);

    $segments = array_pad(DECRYPTED_WII_DOL_SEGMENTS, 18, [0, 0]);
    $offsets = array_map(static fn (array $segment): int => $segment[0] >> 2, $segments);
    $sizes = array_map(static fn (array $segment): int => $segment[1] >> 2, $segments);
    $iso = substr_replace($iso, pack('N*', ...$offsets, ...array_fill(0, 18, 0x80004000), ...$sizes), 0x24000, 0xD8);

    foreach (DECRYPTED_WII_DOL_SEGMENTS as $index => [$offset, $size]) {
        $iso = substr_replace($iso, noiseBytes('segment' . $index, $size), 0x20000 + $offset, $size);
    }

    return $iso;
}

/** The hash of the synthetic decrypted Wii disc, computed like rcheevos does. */
function syntheticDecryptedWiiHash(string $iso): string
{
    $hashed = mb_substr($iso, 0, 0x80, '8bit') . mb_substr($iso, 0x4E000, 4, '8bit')
        . mb_substr($iso, 0x50000 + WII_TMD_OFFSET, 0x100, '8bit') . mb_substr($iso, 0x20000, 0x3660, '8bit');

    foreach (DECRYPTED_WII_DOL_SEGMENTS as [$offset, $size]) {
        $hashed .= mb_substr($iso, 0x20000 + $offset, $size, '8bit');
    }

    return md5($hashed);
}

/**
 * The content sizes of the synthetic WAD, none of them a multiple of the
 * 16 byte AES block nor of the 0x40 byte alignment.
 *
 * @var list<int>
 */
const WAD_CONTENT_SIZES = [0x123, 0x3F8, 0x31];

/** Where the TMD of the synthetic WADs starts: after the header, a certificate chain of 0xA05 bytes and a ticket of 0x2A4, each one aligned to 0x40. */
const WAD_TMD_START = 0xD40;

/**
 * The WAD header and the TMD, padded to 0x40 bytes, of a WiiWare title with
 * the given content sizes (the high and low words of each one).
 *
 * @param  list<array{int, int}>  $contentSizes
 */
function wadHeaderAndTmd(array $contentSizes): string
{
    $tmdSize = 0x1E4 + count($contentSizes) * 0x24;
    $wad = pack('Na4NNNNNN', 0x20, "Is\0\0", 0xA05, 0, 0x2A4, $tmdSize, 0, 0);
    $wad = mb_str_pad($wad, WAD_TMD_START, "\0", STR_PAD_RIGHT, '8bit');
    $tmd = substr_replace(noiseBytes('wad tmd', $tmdSize), pack('n', count($contentSizes)), 0x1DE, 2);

    foreach ($contentSizes as $index => [$high, $low]) {
        $tmd = substr_replace($tmd, pack('NN', $high, $low), 0x1E4 + 8 + $index * 0x24, 8);
    }

    return $wad . mb_str_pad($tmd, ($tmdSize + 0x3F) & ~0x3F, "\0", STR_PAD_RIGHT, '8bit');
}

/** A WiiWare title with three contents, each one padded to 0x40 bytes. */
function syntheticWad(): string
{
    $wad = wadHeaderAndTmd(array_map(static fn (int $size): array => [0, $size], WAD_CONTENT_SIZES));

    foreach (WAD_CONTENT_SIZES as $index => $size) {
        $wad .= mb_str_pad(noiseBytes('content' . $index, $size), ($size + 0x3F) & ~0x3F, "\xFF", STR_PAD_RIGHT, '8bit');
    }

    return $wad;
}

/** The hash of the synthetic WAD, computed like rcheevos does: the TMD and each content up to its AES block. */
function syntheticWadHash(string $wad): string
{
    $tmdSize = (0x1E4 + count(WAD_CONTENT_SIZES) * 0x24 + 0x3F) & ~0x3F;
    $hashed = mb_substr($wad, WAD_TMD_START, $tmdSize, '8bit');
    $address = WAD_TMD_START + $tmdSize;

    foreach (WAD_CONTENT_SIZES as $size) {
        $hashed .= mb_substr($wad, $address, ($size + 0xF) & ~0xF, '8bit');
        $address += ($size + 0x3F) & ~0x3F;
    }

    return md5($hashed);
}

/** The hash RAHasher gives the synthetic encrypted Wii disc. */
const SYNTHETIC_WII_HASH = '2ada7cc40a54deaa2a003841fcf0dc5f';

/** The hash RAHasher gives the synthetic decrypted Wii disc. */
const SYNTHETIC_DECRYPTED_WII_HASH = 'de0fa85743c1ea39259f67a09677133b';

/** The hash RAHasher gives the synthetic WAD. */
const SYNTHETIC_WAD_HASH = 'd0511ba3b68dd3dd805526935a9aa964';

/**
 * A disc held in memory as a few runs of bytes, zeros elsewhere, so a disc
 * of hundreds of megabytes costs only the bytes it stores. Like the real
 * ones, it throws on a range outside it.
 *
 * @param  array<int, string>  $segments  the bytes stored at each offset, none of them overlapping
 */
function sparseNintendoDisc(array $segments, int $size): NintendoDisc
{
    return new readonly class($segments, $size) implements NintendoDisc
    {
        /** @param  array<int, string>  $segments */
        public function __construct(private array $segments, private int $size) {}

        public function read(int $offset, int $length): string
        {
            if ($offset < 0 || $length < 0 || $length > $this->size - $offset) {
                throw new RuntimeException('The read lies outside the sparse disc.');
            }

            $bytes = str_repeat("\0", $length);

            foreach ($this->segments as $start => $segment) {
                $from = max($start, $offset);
                $to = min($start + mb_strlen($segment, '8bit'), $offset + $length);

                if ($from < $to) {
                    $bytes = substr_replace($bytes, mb_substr($segment, $from - $start, $to - $from, '8bit'), $from - $offset, $to - $from);
                }
            }

            return $bytes;
        }

        public function size(): int
        {
            return $this->size;
        }
    };
}

/** The bytes of a dual layer Wii disc, the size of every disc in a WBFS image. */
const WII_DUAL_LAYER_BYTES = 143432 * 2 * 0x8000;

/**
 * A WBFS image of the disc with sectors of 1 << $sectorShift bytes: the
 * header, a copy of the disc header and the sector table in the first
 * sector of the file, then the sectors of the disc where the map puts them,
 * by default in reverse order (the first one last). The sectors the map
 * leaves out or at 0 are not stored.
 *
 * @param  list<int>|null  $map  the sector of the file holding each sector of the disc
 */
function wbfsImage(string $iso, int $sectorShift = 18, int $hdShift = 9, ?array $map = null): string
{
    $sectorSize = 1 << $sectorShift;
    $sectors = intdiv(mb_strlen($iso, '8bit') + $sectorSize - 1, $sectorSize);
    $map ??= array_map(static fn (int $sector): int => $sectors - $sector, range(0, $sectors - 1));
    $fileSectors = array_fill(0, max($map) + 1, str_repeat("\0", $sectorSize));

    foreach ($map as $discSector => $fileSector) {
        if ($fileSector !== 0) {
            $sector = mb_substr($iso, $discSector << $sectorShift, $sectorSize, '8bit');
            $fileSectors[$fileSector] = mb_str_pad($sector, $sectorSize, "\0", STR_PAD_RIGHT, '8bit');
        }
    }

    $table = pack('n*', ...array_pad($map, intdiv(WII_DUAL_LAYER_BYTES + $sectorSize - 1, $sectorSize), 0));
    $discInfo = mb_substr($iso, 0, 0x100, '8bit') . $table;
    $header = pack('a4NCCx2C', 'WBFS', (count($fileSectors) * $sectorSize) >> $hdShift, $hdShift, $sectorShift, 1);
    $fileSectors[0] = substr_replace($fileSectors[0], $header, 0, mb_strlen($header, '8bit'));
    $fileSectors[0] = substr_replace($fileSectors[0], $discInfo, 1 << $hdShift, mb_strlen($discInfo, '8bit'));

    return implode('', $fileSectors);
}

/**
 * Writes a WBFS image cut at the given addresses into Game.wbfs, Game.wbf1,
 * Game.wbf2… (named after $name) and returns the path of the .wbfs part.
 *
 * @param  list<int>  $cuts
 */
function wbfsOnDisk(string $directory, string $image, array $cuts = [], string $name = 'Game.wbfs'): string
{
    $path = $directory . DIRECTORY_SEPARATOR . $name;
    $bounds = [0, ...$cuts, mb_strlen($image, '8bit')];

    for ($part = 0; $part < count($bounds) - 1; $part++) {
        $partPath = $part === 0 ? $path : mb_substr($path, 0, -1, '8bit') . $part;
        file_put_contents($partPath, mb_substr($image, $bounds[$part], $bounds[$part + 1] - $bounds[$part], '8bit'));
    }

    return $path;
}
