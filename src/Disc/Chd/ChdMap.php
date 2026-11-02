<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Disc\Chd;

use RuntimeException;

/**
 * The hunk map of a CHD v5 image, read like libchdr's decompress_v5_map.
 * Entries are kept as packed records and built on demand: 12-byte records
 * of the compressed map (type u8, length u24, offset u48, CRC u16, all big
 * endian, the very records its CRC covers) or the 4-byte hunk numbers of
 * the uncompressed map. Images with a parent are not supported.
 */
final class ChdMap
{
    private const int COMPRESSED_HEADER_BYTES = 16;

    private const int COMPRESSED_RECORD_BYTES = 12;

    private const int UNCOMPRESSED_RECORD_BYTES = 4;

    private const int MAX_FIELD_BITS = 32;

    private const int CRC_BITS = 16;

    private const int LAST_CODEC = 3;

    private const int PARENT = 6;

    /** Repeats the last type for 3 to 18 hunks: 3 plus the next symbol. */
    private const int RLE_SMALL = 7;

    /** Repeats the last type for 19 to 274 hunks: 19 plus the next two symbols as a byte. */
    private const int RLE_LARGE = 8;

    /** SELF of the same hunk as the last SELF. */
    private const int SELF_0 = 9;

    /** SELF of the hunk after the last SELF. */
    private const int SELF_1 = 10;

    private const int PARENT_SELF = 11;

    private const int PARENT_0 = 12;

    private const int PARENT_1 = 13;

    private const int LENGTH_MASK = 0xFFFFFF;

    private const int OFFSET_MASK = 0xFFFFFFFFFFFF;

    /** @var array<int, int> the hunk that holds the data of the hunks that copy another, as found by resolve() */
    private array $resolved = [];

    private function __construct(
        private readonly string $records,
        private readonly int $recordBytes,
        private readonly int $hunkCount,
        private readonly int $hunkBytes,
    ) {}

    /**
     * The map of an image without compression: a big-endian u32 per hunk,
     * the hunk's offset in the file divided by the hunk size, 0 for hunks
     * of zeros.
     */
    public static function uncompressed(string $rawMap, int $hunkCount, int $hunkBytes): self
    {
        if (mb_strlen($rawMap, '8bit') < $hunkCount * self::UNCOMPRESSED_RECORD_BYTES) {
            throw new RuntimeException('The uncompressed CHD map is shorter than its ' . $hunkCount . ' hunks.');
        }

        return new self($rawMap, self::UNCOMPRESSED_RECORD_BYTES, $hunkCount, $hunkBytes);
    }

    /**
     * The map of a compressed image: a 16-byte header (map bytes u32,
     * offset of the first hunk u48, map CRC u16, then the bits of the
     * length, self and parent fields, one byte each, and a reserved byte)
     * followed by a bitstream with the Huffman tree, the types of all hunks
     * and then the fields of each hunk.
     */
    public static function compressed(string $mapData, int $hunkCount, int $hunkBytes): self
    {
        if (mb_strlen($mapData, '8bit') < self::COMPRESSED_HEADER_BYTES) {
            throw new RuntimeException('The compressed CHD map has no header.');
        }

        /** @var array{bytes: int, offsetHigh: int, offsetLow: int, crc: int, lengthBits: int, selfBits: int, parentBits: int} $header */
        $header = unpack('Nbytes/noffsetHigh/NoffsetLow/ncrc/ClengthBits/CselfBits/CparentBits', $mapData);

        if (max($header['lengthBits'], $header['selfBits'], $header['parentBits']) > self::MAX_FIELD_BITS) {
            throw new RuntimeException('The compressed CHD map has fields longer than ' . self::MAX_FIELD_BITS . ' bits.');
        }

        if (mb_strlen($mapData, '8bit') < self::COMPRESSED_HEADER_BYTES + $header['bytes']) {
            throw new RuntimeException('The compressed CHD map is shorter than its header says.');
        }

        $reader = new BitReader(mb_substr($mapData, self::COMPRESSED_HEADER_BYTES, $header['bytes'], '8bit'));
        $types = self::types($reader, HuffmanDecoder::importTreeRle($reader), $hunkCount);
        $records = self::records($reader, $types, ($header['offsetHigh'] << 32) | $header['offsetLow'], $header['lengthBits'], $header['selfBits'], $hunkBytes);

        if ($reader->overflowed()) {
            throw new RuntimeException('The compressed CHD map ends before its last hunk.');
        }

        if (Crc16::compute($records) !== $header['crc']) {
            throw new RuntimeException('The compressed CHD map does not match its CRC.');
        }

        return new self($records, self::COMPRESSED_RECORD_BYTES, $hunkCount, $hunkBytes);
    }

    public function count(): int
    {
        return $this->hunkCount;
    }

    public function entry(int $hunk): ChdMapEntry
    {
        if ($hunk < 0 || $hunk >= $this->hunkCount) {
            throw new RuntimeException('The CHD map has no hunk ' . $hunk . '.');
        }

        $record = mb_substr($this->records, $hunk * $this->recordBytes, $this->recordBytes, '8bit');

        if ($this->recordBytes === self::UNCOMPRESSED_RECORD_BYTES) {
            /** @var array{1: int} $value */
            $value = unpack('N', $record);

            return $value[1] === 0
                ? new ChdMapEntry(ChdMapEntry::ZERO, $this->hunkBytes, 0, null)
                : new ChdMapEntry(ChdMapEntry::UNCOMPRESSED, $this->hunkBytes, $value[1] * $this->hunkBytes, null);
        }

        /** @var array{head: int, tail: int} $fields */
        $fields = unpack('Nhead/Jtail', $record);
        $type = $fields['head'] >> 24;

        return new ChdMapEntry(
            $type,
            $fields['head'] & self::LENGTH_MASK,
            ($fields['tail'] >> 16) & self::OFFSET_MASK,
            $type === ChdMapEntry::SELF ? null : $fields['tail'] & 0xFFFF,
        );
    }

    /**
     * The hunk that holds the data of a hunk: the hunk itself, or for a hunk
     * that copies another, the first one down its chain of copies that does
     * not. Copies always point to an earlier hunk, so the chain ends.
     */
    public function resolve(int $hunk): int
    {
        $source = $hunk;

        while (($entry = $this->entry($source))->type === ChdMapEntry::SELF) {
            if (isset($this->resolved[$source])) {
                $source = $this->resolved[$source];

                break;
            }

            $source = $entry->offset;
        }

        if ($source !== $hunk) {
            $this->resolved[$hunk] = $source;
        }

        return $source;
    }

    /**
     * The first pass: the Huffman coded type of every hunk, with runs of
     * the last type.
     *
     * @return list<int>
     */
    private static function types(BitReader $reader, HuffmanDecoder $decoder, int $hunkCount): array
    {
        $types = [];
        $lastType = 0;

        while (count($types) < $hunkCount) {
            if ($reader->overflowed()) {
                throw new RuntimeException('The compressed CHD map ends before its last hunk.');
            }

            $symbol = $decoder->decode($reader);

            if ($symbol === self::RLE_SMALL || $symbol === self::RLE_LARGE) {
                $repeat = $symbol === self::RLE_SMALL
                    ? 3 + $decoder->decode($reader)
                    : 3 + 16 + ($decoder->decode($reader) << 4) + $decoder->decode($reader);

                if (count($types) + $repeat > $hunkCount) {
                    throw new RuntimeException('The compressed CHD map repeats a type past its last hunk.');
                }

                array_push($types, ...array_fill(0, $repeat, $lastType));

                continue;
            }

            $types[] = $lastType = $symbol;
        }

        return $types;
    }

    /**
     * The second pass: the fields of every hunk, packed as the records the
     * map CRC covers. Lengths and offsets are cut to 24 and 48 bits, like
     * libchdr does when it stores them, but the offset of the next hunk
     * advances by the whole length that was read.
     *
     * @param  list<int>  $types
     */
    private static function records(BitReader $reader, array $types, int $offset, int $lengthBits, int $selfBits, int $hunkBytes): string
    {
        $records = '';
        $lastSelf = 0;

        foreach ($types as $hunk => $type) {
            if ($reader->overflowed()) {
                throw new RuntimeException('The compressed CHD map ends before its last hunk.');
            }

            [$type, $length, $hunkOffset, $crc] = match (true) {
                $type <= self::LAST_CODEC => [$type, $reader->read($lengthBits), $offset, $reader->read(self::CRC_BITS)],
                $type === ChdMapEntry::UNCOMPRESSED => [$type, $hunkBytes, $offset, $reader->read(self::CRC_BITS)],
                $type === ChdMapEntry::SELF => [$type, 0, $lastSelf = $reader->read($selfBits), 0],
                $type === self::SELF_0 => [ChdMapEntry::SELF, 0, $lastSelf, 0],
                $type === self::SELF_1 => [ChdMapEntry::SELF, 0, ++$lastSelf, 0],
                in_array($type, [self::PARENT, self::PARENT_SELF, self::PARENT_0, self::PARENT_1], true) => throw new RuntimeException('The CHD image needs a parent image.'),
                default => throw new RuntimeException('The compressed CHD map has the unknown hunk type ' . $type . '.'),
            };

            $hunkOffset &= self::OFFSET_MASK;

            if ($type === ChdMapEntry::SELF && $hunkOffset >= $hunk) {
                throw new RuntimeException('Hunk ' . $hunk . ' of the CHD map copies hunk ' . $hunkOffset . ', which does not come before it.');
            }

            if ($type !== ChdMapEntry::SELF) {
                $offset += $length;
            }

            $records .= pack('NJ', ($type << 24) | ($length & self::LENGTH_MASK), ($hunkOffset << 16) | $crc);
        }

        return $records;
    }
}
