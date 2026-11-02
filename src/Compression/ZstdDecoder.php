<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Compression;

use RuntimeException;

/**
 * Decodes Zstandard data (RFC 8878), like the groups of Dolphin's RVZ disc
 * images: every frame of the input is decoded and their outputs are joined,
 * skippable frames are passed over and frames that need a dictionary are
 * rejected. The content checksum of a frame is checked when it has one, with
 * the XXH64 of PHP's hash extension.
 *
 * Follows the decoding steps of the RFC and of the reference decoder of
 * zstd/lib/decompress and its educational decoder: frame and block headers,
 * raw, RLE and Huffman literals (one or four streams, new or repeated tables,
 * direct or FSE-compressed weights), sequences with predefined, RLE, FSE or
 * repeated tables, and the three repeat offsets. The window is the whole
 * output of the frame, so the Window_Descriptor is only skipped, but matches
 * may not refer to data before the start of their frame.
 *
 * Bits of the backward streams are read through an integer cache, written
 * out in the Huffman and sequence loops, because a method call per symbol
 * makes decoding several times slower.
 */
final class ZstdDecoder
{
    private const int FRAME_MAGIC = 0xFD2FB528;

    private const int SKIPPABLE_MAGIC = 0x184D2A50;

    private const int SKIPPABLE_MAGIC_MASK = 0xFFFFFFF0;

    /** Zeros after the input, so headers and table descriptions can be read before their sizes are checked. */
    private const int INPUT_PADDING = 1024;

    private const int RAW_BLOCK = 0;

    private const int RLE_BLOCK = 1;

    private const int COMPRESSED_BLOCK = 2;

    private const int RAW_LITERALS = 0;

    private const int RLE_LITERALS = 1;

    private const int COMPRESSED_LITERALS = 2;

    private const int MAX_HUFFMAN_BITS = 11;

    private const int MAX_HUFFMAN_WEIGHTS = 255;

    private const int MAX_WEIGHTS_ACCURACY_LOG = 6;

    /** The bits the cache holds at least after a refill. */
    private const int REFILLED_BITS = 57;

    private const int LITERAL_LENGTHS = 0;

    private const int OFFSETS = 1;

    private const int MATCH_LENGTHS = 2;

    private const int PREDEFINED_MODE = 0;

    private const int RLE_MODE = 1;

    private const int COMPRESSED_MODE = 2;

    /** The largest accuracy log of the literal length, offset and match length tables. */
    private const array MAX_ACCURACY_LOGS = [9, 8, 9];

    /** The largest literal length, offset and match length codes. */
    private const array MAX_CODES = [35, 31, 52];

    private const array PREDEFINED_ACCURACY_LOGS = [6, 5, 6];

    /** The default distributions of the literal length, offset and match length codes. */
    private const array PREDEFINED_DISTRIBUTIONS = [
        [4, 3, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 1, 1, 1, 2, 2, 2, 2, 2, 2, 2, 2, 2, 3, 2, 1, 1, 1, 1, 1, -1, -1, -1, -1],
        [1, 1, 1, 1, 1, 1, 2, 2, 2, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, -1, -1, -1, -1, -1],
        [
            1, 4, 3, 2, 2, 2, 2, 2, 2, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1,
            1, 1, 1, 1, 1, 1, 1, 1, 1, -1, -1, -1, -1, -1, -1, -1,
        ],
    ];

    private const array LITERAL_LENGTH_BASES = [
        0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 18, 20, 22, 24, 28, 32, 40, 48, 64, 128, 256, 512,
        1024, 2048, 4096, 8192, 16384, 32768, 65536,
    ];

    private const array LITERAL_LENGTH_EXTRA_BITS = [
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 2, 2, 3, 3, 4, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16,
    ];

    private const array MATCH_LENGTH_BASES = [
        3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32,
        33, 34, 35, 37, 39, 41, 43, 47, 51, 59, 67, 83, 99, 131, 259, 515, 1027, 2051, 4099, 8195, 16387, 32771, 65539,
    ];

    private const array MATCH_LENGTH_EXTRA_BITS = [
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 2, 2,
        3, 3, 4, 4, 5, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16,
    ];

    /** @var array<int, array{list<int>, int}> the predefined sequence tables, built once */
    private static array $predefinedTables = [];

    private readonly string $input;

    private readonly int $inputLength;

    private int $position = 0;

    private string $output = '';

    /** Where the frame being decoded starts in the output. */
    private int $frameStart = 0;

    /** @var list<string> the byte of every Huffman code, by its first bits */
    private array $huffmanBytes = [];

    /** @var list<int> the length of every Huffman code, by its first bits */
    private array $huffmanLengths = [];

    /** The longest Huffman code of the frame's table, 0 until the frame sends one. */
    private int $huffmanBits = 0;

    /** @var array<int, array{list<int>, int}> the last sequence tables of the frame and their accuracy logs */
    private array $sequenceTables = [];

    /** @var array{int, int, int} */
    private array $repeatOffsets = [1, 4, 8];

    private function __construct(string $compressed)
    {
        $this->inputLength = mb_strlen($compressed, '8bit');
        $this->input = $compressed . str_repeat("\0", self::INPUT_PADDING);
    }

    /**
     * @param  int|null  $expectedSize  the size the output must have, if known
     *
     * @throws RuntimeException when the data is not a sequence of valid frames, needs a dictionary or does not decode to $expectedSize bytes
     */
    public static function decode(string $compressed, ?int $expectedSize = null): string
    {
        $decoder = new self($compressed);

        do {
            $decoder->decodeFrame();
        } while ($decoder->position < $decoder->inputLength);

        if ($expectedSize !== null && mb_strlen($decoder->output, '8bit') !== $expectedSize) {
            throw new RuntimeException('The zstd data does not decode to the expected size.');
        }

        return $decoder->output;
    }

    /**
     * Spreads the symbols of the normalized counts over the states of an FSE table.
     *
     * @param  list<int>  $counts
     * @return array{array<int, int>, list<int>, list<int>} the symbol, the bits to read and the next state baseline of every state
     */
    private static function buildFseTable(array $counts, int $accuracyLog): array
    {
        $size = 1 << $accuracyLog;
        $highThreshold = $size - 1;
        $symbols = array_fill(0, $size, 0);
        $nextStates = [];

        foreach ($counts as $symbol => $count) {
            if ($count === -1) {
                $symbols[$highThreshold--] = $symbol;
                $nextStates[$symbol] = 1;
            } else {
                $nextStates[$symbol] = $count;
            }
        }

        $step = ($size >> 1) + ($size >> 3) + 3;
        $state = 0;

        foreach ($counts as $symbol => $count) {
            for ($occurrence = 0; $occurrence < $count; $occurrence++) {
                $symbols[$state] = $symbol;

                do {
                    $state = ($state + $step) & ($size - 1);
                } while ($state > $highThreshold);
            }
        }

        $stateBits = [];
        $baselines = [];

        foreach ($symbols as $symbol) {
            $nextState = $nextStates[$symbol]++;
            $bits = $accuracyLog - self::highestBit($nextState);
            $stateBits[] = $bits;
            $baselines[] = ($nextState << $bits) - $size;
        }

        return [$symbols, $stateBits, $baselines];
    }

    /**
     * Packs every state of a sequence table with the base value and extra bits of its code.
     *
     * @param  array<int, int>  $symbols  by state
     * @param  list<int>  $stateBits
     * @param  list<int>  $baselines
     * @return list<int>
     */
    private static function packSequenceTable(int $kind, array $symbols, array $stateBits, array $baselines): array
    {
        $table = [];

        foreach ($symbols as $state => $code) {
            [$base, $extraBits] = match ($kind) {
                self::LITERAL_LENGTHS => [self::LITERAL_LENGTH_BASES[$code], self::LITERAL_LENGTH_EXTRA_BITS[$code]],
                self::OFFSETS => [1 << $code, $code],
                default => [self::MATCH_LENGTH_BASES[$code], self::MATCH_LENGTH_EXTRA_BITS[$code]],
            };
            $table[] = $baselines[$state] | ($stateBits[$state] << 10) | ($extraBits << 14) | ($base << 19);
        }

        return $table;
    }

    /**
     * The cache of a backward bitstream that starts at its last byte, under the marker bit that ends it.
     *
     * @return array{int, int} the cache and the bits it holds
     */
    private static function startBackwardStream(string $input, int $start, int $end): array
    {
        $lastByte = $end > $start ? ord($input[$end - 1]) : 0;

        if ($lastByte === 0) {
            throw new RuntimeException('The zstd bitstream does not end with its marker bit.');
        }

        return [$lastByte, self::highestBit($lastByte)];
    }

    /**
     * Reads $count bits of a backward bitstream, with zeros past its start that $paddingBits counts.
     */
    private static function readBackward(string $input, int $start, int &$bytePosition, int &$cache, int &$bits, int &$paddingBits, int $count): int
    {
        while ($bits <= 56) {
            if ($bytePosition >= $start) {
                $cache = ($cache << 8) | ord($input[$bytePosition--]);
            } else {
                $cache <<= 8;
                $paddingBits += 8;
            }

            $bits += 8;
        }

        $bits -= $count;

        return ($cache >> $bits) & ((1 << $count) - 1);
    }

    private static function highestBit(int $value): int
    {
        $bit = -1;

        while ($value > 0) {
            $value >>= 1;
            $bit++;
        }

        return $bit;
    }

    /**
     * Decodes the frame at the current position, or passes over it when it is a skippable frame.
     */
    private function decodeFrame(): void
    {
        $this->need(4);
        $magic = $this->littleEndian($this->position, 4);

        if (($magic & self::SKIPPABLE_MAGIC_MASK) === self::SKIPPABLE_MAGIC) {
            $this->need(8);
            $skippedBytes = $this->littleEndian($this->position + 4, 4);
            $this->position += 8;
            $this->need($skippedBytes);
            $this->position += $skippedBytes;

            return;
        }

        if ($magic !== self::FRAME_MAGIC) {
            throw new RuntimeException('The data is not a zstd frame.');
        }

        $this->need(5);
        $descriptor = ord($this->input[$this->position + 4]);

        if (($descriptor & 0x08) !== 0) {
            throw new RuntimeException('The zstd frame header sets its reserved bit.');
        }

        $singleSegment = ($descriptor >> 5) & 1;
        $hasChecksum = ($descriptor & 0x04) !== 0;
        $dictionaryIdBytes = [0, 1, 2, 4][$descriptor & 3];
        $contentSizeBytes = [$singleSegment, 2, 4, 8][$descriptor >> 6];
        $dictionaryIdStart = $this->position + 6 - $singleSegment;
        $this->need(6 - $singleSegment + $dictionaryIdBytes + $contentSizeBytes);

        if ($this->littleEndian($dictionaryIdStart, $dictionaryIdBytes) !== 0) {
            throw new RuntimeException('The zstd frame needs a dictionary, which is not supported.');
        }

        $contentSize = $this->littleEndian($dictionaryIdStart + $dictionaryIdBytes, $contentSizeBytes) + ($contentSizeBytes === 2 ? 256 : 0);
        $this->position = $dictionaryIdStart + $dictionaryIdBytes + $contentSizeBytes;
        $this->frameStart = mb_strlen($this->output, '8bit');
        $this->huffmanBits = 0;
        $this->sequenceTables = [];
        $this->repeatOffsets = [1, 4, 8];

        do {
            $this->need(3);
            $blockHeader = $this->littleEndian($this->position, 3);
            $blockType = ($blockHeader >> 1) & 3;
            $blockSize = $blockHeader >> 3;
            $this->position += 3;

            if ($blockType === self::RAW_BLOCK) {
                $this->need($blockSize);
                $this->output .= mb_substr($this->input, $this->position, $blockSize, '8bit');
                $this->position += $blockSize;
            } elseif ($blockType === self::RLE_BLOCK) {
                $this->need(1);
                $this->output .= str_repeat($this->input[$this->position], $blockSize);
                $this->position++;
            } elseif ($blockType === self::COMPRESSED_BLOCK) {
                $this->need($blockSize);
                $this->decodeCompressedBlock($this->position, $this->position + $blockSize);
                $this->position += $blockSize;
            } else {
                throw new RuntimeException('The zstd frame holds a block of the reserved type.');
            }
        } while (($blockHeader & 1) === 0);

        $frameSize = mb_strlen($this->output, '8bit') - $this->frameStart;

        if ($hasChecksum) {
            $this->need(4);
            $digest = hash('xxh64', mb_substr($this->output, $this->frameStart, null, '8bit'), true);

            if (strrev(mb_substr($digest, 4, 4, '8bit')) !== mb_substr($this->input, $this->position, 4, '8bit')) {
                throw new RuntimeException('The zstd frame does not match its checksum.');
            }

            $this->position += 4;
        }

        if ($contentSizeBytes > 0 && $frameSize !== $contentSize) {
            throw new RuntimeException('The zstd frame does not decode to its content size.');
        }
    }

    /**
     * Decodes the literals and then the sequences of the compressed block between $start and $end.
     */
    private function decodeCompressedBlock(int $start, int $end): void
    {
        [$literals, $position] = $this->decodeLiterals($start, $end);
        $input = $this->input;
        $first = ord($input[$position]);

        if ($first < 128) {
            $sequenceCount = $first;
            $position++;
        } elseif ($first < 255) {
            $sequenceCount = (($first - 128) << 8) + ord($input[$position + 1]);
            $position += 2;
        } else {
            $sequenceCount = ord($input[$position + 1]) + (ord($input[$position + 2]) << 8) + 0x7F00;
            $position += 3;
        }

        if ($sequenceCount === 0) {
            if ($position !== $end) {
                throw new RuntimeException('The zstd sequences section does not fit its block.');
            }

            $this->output .= $literals;

            return;
        }

        $modes = ord($input[$position++]);

        if (($modes & 3) !== 0) {
            throw new RuntimeException('The zstd sequences section sets its reserved bits.');
        }

        [$literalLengthTable, $literalLengthLog] = $this->sequenceTable(self::LITERAL_LENGTHS, $modes >> 6, $position, $end);
        [$offsetTable, $offsetLog] = $this->sequenceTable(self::OFFSETS, ($modes >> 4) & 3, $position, $end);
        [$matchLengthTable, $matchLengthLog] = $this->sequenceTable(self::MATCH_LENGTHS, ($modes >> 2) & 3, $position, $end);

        [$cache, $bits] = self::startBackwardStream($input, $position, $end);
        $bytePosition = $end - 2;
        $paddingBits = 0;
        $literalLengthState = self::readBackward($input, $position, $bytePosition, $cache, $bits, $paddingBits, $literalLengthLog);
        $offsetState = self::readBackward($input, $position, $bytePosition, $cache, $bits, $paddingBits, $offsetLog);
        $matchLengthState = self::readBackward($input, $position, $bytePosition, $cache, $bits, $paddingBits, $matchLengthLog);

        [$repeat1, $repeat2, $repeat3] = $this->repeatOffsets;
        $output = $this->output;
        $this->output = '';
        $outputLength = mb_strlen($output, '8bit');
        $frameStart = $this->frameStart;
        $literalsLength = mb_strlen($literals, '8bit');
        $literalPosition = 0;

        for ($sequence = 1; $sequence <= $sequenceCount; $sequence++) {
            while ($bits <= 56) {
                if ($bytePosition >= $position) {
                    $cache = ($cache << 8) | ord($input[$bytePosition--]);
                } else {
                    $cache <<= 8;
                    $paddingBits += 8;
                }

                $bits += 8;
            }

            $offsetEntry = $offsetTable[$offsetState];
            $matchLengthEntry = $matchLengthTable[$matchLengthState];
            $literalLengthEntry = $literalLengthTable[$literalLengthState];

            $extraBits = ($offsetEntry >> 14) & 31;
            $offsetValue = ($offsetEntry >> 19) + (($cache >> ($bits - $extraBits)) & ((1 << $extraBits) - 1));
            $bits -= $extraBits;

            $extraBits = ($matchLengthEntry >> 14) & 31;
            $matchLength = ($matchLengthEntry >> 19) + (($cache >> ($bits - $extraBits)) & ((1 << $extraBits) - 1));
            $bits -= $extraBits;

            while ($bits <= 56) {
                if ($bytePosition >= $position) {
                    $cache = ($cache << 8) | ord($input[$bytePosition--]);
                } else {
                    $cache <<= 8;
                    $paddingBits += 8;
                }

                $bits += 8;
            }

            $extraBits = ($literalLengthEntry >> 14) & 31;
            $literalLength = ($literalLengthEntry >> 19) + (($cache >> ($bits - $extraBits)) & ((1 << $extraBits) - 1));
            $bits -= $extraBits;

            if ($sequence < $sequenceCount) {
                $stateBits = ($literalLengthEntry >> 10) & 15;
                $literalLengthState = ($literalLengthEntry & 1023) + (($cache >> ($bits - $stateBits)) & ((1 << $stateBits) - 1));
                $bits -= $stateBits;

                $stateBits = ($matchLengthEntry >> 10) & 15;
                $matchLengthState = ($matchLengthEntry & 1023) + (($cache >> ($bits - $stateBits)) & ((1 << $stateBits) - 1));
                $bits -= $stateBits;

                $stateBits = ($offsetEntry >> 10) & 15;
                $offsetState = ($offsetEntry & 1023) + (($cache >> ($bits - $stateBits)) & ((1 << $stateBits) - 1));
                $bits -= $stateBits;
            }

            if ($offsetValue > 3) {
                $repeat3 = $repeat2;
                $repeat2 = $repeat1;
                $repeat1 = $offsetValue - 3;
            } else {
                // Without literals, the repeat codes point one offset further, and the third means the first minus one.
                $repeatIndex = $literalLength === 0 ? $offsetValue : $offsetValue - 1;

                if ($repeatIndex === 1) {
                    $offset = $repeat2;
                    $repeat2 = $repeat1;
                    $repeat1 = $offset;
                } elseif ($repeatIndex === 2) {
                    $offset = $repeat3;
                    $repeat3 = $repeat2;
                    $repeat2 = $repeat1;
                    $repeat1 = $offset;
                } elseif ($repeatIndex === 3) {
                    $repeat3 = $repeat2;
                    $repeat2 = $repeat1;
                    $repeat1--;
                }
            }

            if ($literalLength > 0) {
                if ($literalLength > $literalsLength - $literalPosition) {
                    throw new RuntimeException('The zstd sequences use more literals than their block holds.');
                }

                $output .= mb_substr($literals, $literalPosition, $literalLength, '8bit');
                $literalPosition += $literalLength;
                $outputLength += $literalLength;
            }

            if ($repeat1 < 1 || $repeat1 > $outputLength - $frameStart) {
                throw new RuntimeException('The zstd sequences refer to data before the start of their frame.');
            }

            if ($matchLength <= $repeat1) {
                $output .= mb_substr($output, $outputLength - $repeat1, $matchLength, '8bit');
            } else {
                $pattern = mb_substr($output, $outputLength - $repeat1, $repeat1, '8bit');
                $output .= mb_substr(str_repeat($pattern, intdiv($matchLength, $repeat1) + 1), 0, $matchLength, '8bit');
            }

            $outputLength += $matchLength;
        }

        if ($bytePosition >= $position || $bits !== $paddingBits) {
            throw new RuntimeException('The zstd sequences bitstream does not end with its last sequence.');
        }

        $this->output = $output . mb_substr($literals, $literalPosition, null, '8bit');
        $this->repeatOffsets = [$repeat1, $repeat2, $repeat3];
    }

    /**
     * @return array{string, int} the literals of the block and where its sequences section starts
     */
    private function decodeLiterals(int $start, int $end): array
    {
        $header = ord($this->input[$start]);
        $type = $header & 3;
        $sizeFormat = ($header >> 2) & 3;

        if ($type === self::RAW_LITERALS || $type === self::RLE_LITERALS) {
            [$headerSize, $size] = match ($sizeFormat) {
                1 => [2, $this->littleEndian($start, 2) >> 4],
                3 => [3, $this->littleEndian($start, 3) >> 4],
                default => [1, $header >> 3],
            };
            $contentSize = $type === self::RAW_LITERALS ? $size : 1;

            if ($start + $headerSize + $contentSize > $end) {
                throw new RuntimeException('The zstd literals section does not fit its block.');
            }

            $literals = $type === self::RAW_LITERALS
                ? mb_substr($this->input, $start + $headerSize, $size, '8bit')
                : str_repeat($this->input[$start + $headerSize], $size);

            return [$literals, $start + $headerSize + $contentSize];
        }

        $headerSize = [3, 3, 4, 5][$sizeFormat];
        $sizeBits = [10, 10, 14, 18][$sizeFormat];
        $headerValue = $this->littleEndian($start, $headerSize);
        $size = ($headerValue >> 4) & ((1 << $sizeBits) - 1);
        $literalsEnd = $start + $headerSize + ($headerValue >> (4 + $sizeBits));

        if ($literalsEnd > $end) {
            throw new RuntimeException('The zstd literals section does not fit its block.');
        }

        $position = $start + $headerSize;

        if ($type === self::COMPRESSED_LITERALS) {
            $position = $this->readHuffmanTable($position, $literalsEnd);
        } elseif ($this->huffmanBits === 0) {
            throw new RuntimeException('The zstd literals reuse a Huffman table that their frame never sent.');
        }

        if ($sizeFormat === 0) {
            return [$this->decodeHuffmanStream($position, $literalsEnd, $size), $literalsEnd];
        }

        $streamSize = intdiv($size + 3, 4);
        $secondStart = $position + 6 + $this->littleEndian($position, 2);
        $thirdStart = $secondStart + $this->littleEndian($position + 2, 2);
        $fourthStart = $thirdStart + $this->littleEndian($position + 4, 2);

        if ($fourthStart > $literalsEnd || 3 * $streamSize > $size) {
            throw new RuntimeException('The zstd literals streams do not fit their section.');
        }

        $literals = $this->decodeHuffmanStream($position + 6, $secondStart, $streamSize)
            . $this->decodeHuffmanStream($secondStart, $thirdStart, $streamSize)
            . $this->decodeHuffmanStream($thirdStart, $fourthStart, $streamSize)
            . $this->decodeHuffmanStream($fourthStart, $literalsEnd, $size - 3 * $streamSize);

        return [$literals, $literalsEnd];
    }

    /**
     * Reads the Huffman tree description at $start and builds the decoding table of its codes.
     *
     * @return int where the description ends
     */
    private function readHuffmanTable(int $start, int $limit): int
    {
        $input = $this->input;
        $header = ord($input[$start]);
        $weights = [];

        if ($header >= 128) {
            $end = $start + 1 + (($header - 126) >> 1);

            if ($end > $limit) {
                throw new RuntimeException('The zstd Huffman table does not fit its literals section.');
            }

            for ($symbol = 0; $symbol < $header - 127; $symbol++) {
                $byte = ord($input[$start + 1 + ($symbol >> 1)]);
                $weights[] = ($symbol & 1) === 0 ? $byte >> 4 : $byte & 15;
            }

            $ended = true;
        } else {
            $end = $start + 1 + $header;

            if ($end > $limit) {
                throw new RuntimeException('The zstd Huffman table does not fit its literals section.');
            }

            [$counts, $accuracyLog, $streamStart] = $this->readDistribution($start + 1, $end, self::MAX_WEIGHTS_ACCURACY_LOG, self::MAX_HUFFMAN_WEIGHTS);
            [$symbols, $stateBits, $baselines] = self::buildFseTable($counts, $accuracyLog);
            [$cache, $bits] = self::startBackwardStream($input, $streamStart, $end);
            $bytePosition = $end - 2;
            $paddingBits = 0;
            $state1 = self::readBackward($input, $streamStart, $bytePosition, $cache, $bits, $paddingBits, $accuracyLog);
            $state2 = self::readBackward($input, $streamStart, $bytePosition, $cache, $bits, $paddingBits, $accuracyLog);
            $ended = false;

            // The two states take turns until a state update reads past the start of the stream: the other state then gives the last weight.
            while (count($weights) < self::MAX_HUFFMAN_WEIGHTS) {
                $weights[] = $symbols[$state1];
                $state1 = $baselines[$state1] + self::readBackward($input, $streamStart, $bytePosition, $cache, $bits, $paddingBits, $stateBits[$state1]);

                if ($bytePosition < $streamStart && $bits < $paddingBits) {
                    $weights[] = $symbols[$state2];
                    $ended = true;

                    break;
                }

                $weights[] = $symbols[$state2];
                $state2 = $baselines[$state2] + self::readBackward($input, $streamStart, $bytePosition, $cache, $bits, $paddingBits, $stateBits[$state2]);

                if ($bytePosition < $streamStart && $bits < $paddingBits) {
                    $weights[] = $symbols[$state1];
                    $ended = true;

                    break;
                }
            }
        }

        $total = 0;

        foreach ($weights as $weight) {
            $total += $weight > 0 ? 1 << min($weight - 1, self::MAX_HUFFMAN_BITS) : 0;
        }

        $maxBits = $total > 0 ? self::highestBit($total) + 1 : 0;
        $rest = (1 << $maxBits) - $total;

        if (! $ended || count($weights) > self::MAX_HUFFMAN_WEIGHTS || $total === 0 || $maxBits > self::MAX_HUFFMAN_BITS || ($rest & ($rest - 1)) !== 0) {
            throw new RuntimeException('The zstd Huffman table is corrupt.');
        }

        $weights[] = self::highestBit($rest) + 1;
        $bytes = [];
        $lengths = [];

        for ($weight = 1; $weight <= $maxBits; $weight++) {
            foreach ($weights as $symbol => $symbolWeight) {
                if ($symbolWeight === $weight) {
                    for ($code = 1 << ($weight - 1); $code > 0; $code--) {
                        $bytes[] = chr($symbol);
                        $lengths[] = $maxBits + 1 - $weight;
                    }
                }
            }
        }

        $this->huffmanBytes = $bytes;
        $this->huffmanLengths = $lengths;
        $this->huffmanBits = $maxBits;

        return $end;
    }

    /**
     * Decodes the $count literals of the Huffman stream between $start and $end, which must end with them.
     */
    private function decodeHuffmanStream(int $start, int $end, int $count): string
    {
        $input = $this->input;
        [$cache, $bits] = self::startBackwardStream($input, $start, $end);
        $bytePosition = $end - 2;
        $paddingBits = 0;
        $bytes = $this->huffmanBytes;
        $lengths = $this->huffmanLengths;
        $maxBits = $this->huffmanBits;
        $mask = (1 << $maxBits) - 1;
        $codesPerRefill = intdiv(self::REFILLED_BITS, $maxBits);
        $literals = '';

        while ($count > 0) {
            while ($bits <= 56) {
                if ($bytePosition >= $start) {
                    $cache = ($cache << 8) | ord($input[$bytePosition--]);
                } else {
                    $cache <<= 8;
                    $paddingBits += 8;
                }

                $bits += 8;
            }

            $codes = min($count, $codesPerRefill);
            $count -= $codes;

            for (; $codes > 0; $codes--) {
                $index = ($cache >> ($bits - $maxBits)) & $mask;
                $literals .= $bytes[$index];
                $bits -= $lengths[$index];
            }
        }

        if ($bytePosition >= $start || $bits !== $paddingBits) {
            throw new RuntimeException('The zstd Huffman stream does not end with its literals.');
        }

        return $literals;
    }

    /**
     * The decoding table of the sequence codes of $kind in $mode, read at $position when the block sends it.
     *
     * @return array{list<int>, int} the packed states (next state baseline, state bits, extra bits, value base) and the accuracy log
     */
    private function sequenceTable(int $kind, int $mode, int &$position, int $end): array
    {
        if ($mode === self::PREDEFINED_MODE) {
            $table = self::$predefinedTables[$kind] ??= [
                self::packSequenceTable($kind, ...self::buildFseTable(self::PREDEFINED_DISTRIBUTIONS[$kind], self::PREDEFINED_ACCURACY_LOGS[$kind])),
                self::PREDEFINED_ACCURACY_LOGS[$kind],
            ];
        } elseif ($mode === self::RLE_MODE) {
            $code = ord($this->input[$position++]);

            if ($code > self::MAX_CODES[$kind]) {
                throw new RuntimeException('The zstd sequences repeat a code out of range.');
            }

            $table = [self::packSequenceTable($kind, [$code], [0], [0]), 0];
        } elseif ($mode === self::COMPRESSED_MODE) {
            [$counts, $accuracyLog, $position] = $this->readDistribution($position, $end, self::MAX_ACCURACY_LOGS[$kind], self::MAX_CODES[$kind]);
            $table = [self::packSequenceTable($kind, ...self::buildFseTable($counts, $accuracyLog)), $accuracyLog];
        } else {
            $table = $this->sequenceTables[$kind] ?? throw new RuntimeException('The zstd sequences reuse a table that their frame never sent.');
        }

        return $this->sequenceTables[$kind] = $table;
    }

    /**
     * Reads the FSE table description at $start, which may not go past $end.
     *
     * @return array{list<int>, int, int} the normalized counts (-1 for less than one), the accuracy log and where the description ends
     */
    private function readDistribution(int $start, int $end, int $maxAccuracyLog, int $maxSymbol): array
    {
        $bitPosition = $start * 8;
        $accuracyLog = ($this->forwardBits($bitPosition) & 15) + 5;
        $bitPosition += 4;
        $remaining = (1 << $accuracyLog) + 1;
        $threshold = 1 << $accuracyLog;
        $valueBits = $accuracyLog + 1;
        $counts = [];
        $previousZero = false;

        while (true) {
            if ($previousZero) {
                do {
                    $repeat = $this->forwardBits($bitPosition) & 3;
                    $bitPosition += 2;

                    for ($zero = 0; $zero < $repeat; $zero++) {
                        $counts[] = 0;
                    }
                } while ($repeat === 3);

                if (count($counts) > $maxSymbol) {
                    break;
                }
            }

            $value = $this->forwardBits($bitPosition);
            $largest = 2 * $threshold - 1 - $remaining;

            if (($value & ($threshold - 1)) < $largest) {
                $count = $value & ($threshold - 1);
                $bitPosition += $valueBits - 1;
            } else {
                $count = $value & (2 * $threshold - 1);
                $count -= $count >= $threshold ? $largest : 0;
                $bitPosition += $valueBits;
            }

            $count--;
            $remaining -= abs($count);
            $counts[] = $count;
            $previousZero = $count === 0;

            if ($remaining < $threshold) {
                if ($remaining <= 1) {
                    break;
                }

                $valueBits = self::highestBit($remaining) + 1;
                $threshold = 1 << ($valueBits - 1);
            }

            if (count($counts) > $maxSymbol) {
                break;
            }
        }

        $descriptionEnd = ($bitPosition + 7) >> 3;

        if ($accuracyLog > $maxAccuracyLog || $remaining !== 1 || count($counts) > $maxSymbol + 1 || $descriptionEnd > $end) {
            throw new RuntimeException('The zstd FSE table description is corrupt.');
        }

        return [$counts, $accuracyLog, $descriptionEnd];
    }

    /**
     * The bits of the input from $bitPosition on, least significant first: at least 25 of them.
     */
    private function forwardBits(int $bitPosition): int
    {
        return $this->littleEndian($bitPosition >> 3, 4) >> ($bitPosition & 7);
    }

    private function littleEndian(int $offset, int $bytes): int
    {
        $value = 0;

        for ($byte = 0; $byte < $bytes; $byte++) {
            $value |= ord($this->input[$offset + $byte]) << (8 * $byte);
        }

        return $value;
    }

    /**
     * @throws RuntimeException when fewer than $bytes bytes are left after the current position
     */
    private function need(int $bytes): void
    {
        if ($this->position + $bytes > $this->inputLength) {
            throw new RuntimeException('The zstd data is truncated.');
        }
    }
}
