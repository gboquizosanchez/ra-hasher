<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Compression;

use RuntimeException;

/**
 * Decodes raw LZMA1 streams of a known size, like the "lzma" and "cdlz"
 * hunks of CHD images (libchdr's lzma codec): no header, the properties
 * come from the caller, and the stream may end with an end marker or not.
 * Each stream stands on its own, so the whole output is the dictionary.
 *
 * Follows the reference decoder of the LZMA SDK (LzmaSpec.cpp) and, like
 * LzmaDec_DecodeToDic with LZMA_FINISH_END, only accepts streams that
 * produce exactly the expected size and are read to their last byte.
 * The range decoder is written out at every bit, like the macros of
 * LzmaDec.c, because a method call per bit makes decoding several times
 * slower.
 */
final class LzmaDecoder
{
    private const int TOP_VALUE = 1 << 24;

    private const int PROBABILITY_BITS = 11;

    private const int PROBABILITY_TOTAL = 1 << self::PROBABILITY_BITS;

    private const int MOVE_BITS = 5;

    /** The most bytes a symbol can read: one per bit of the longest match, end marker included. */
    private const int INPUT_PADDING = 64;

    private const int END_MARKER = 0xFFFFFFFF;

    private const int STATES = 12;

    private const int MAX_POSITION_BITS = 4;

    private const int END_POSITION_MODEL_INDEX = 14;

    private const int FULL_DISTANCES = 128;

    private const int ALIGN_BITS = 4;

    private const int IS_MATCH = 0;

    private const int IS_REP = self::IS_MATCH + (self::STATES << self::MAX_POSITION_BITS);

    private const int IS_REP_G0 = self::IS_REP + self::STATES;

    private const int IS_REP_G1 = self::IS_REP_G0 + self::STATES;

    private const int IS_REP_G2 = self::IS_REP_G1 + self::STATES;

    private const int IS_REP0_LONG = self::IS_REP_G2 + self::STATES;

    private const int POSITION_SLOT = self::IS_REP0_LONG + (self::STATES << self::MAX_POSITION_BITS);

    private const int SPECIAL_POSITION = self::POSITION_SLOT + (4 << 6);

    private const int ALIGN = self::SPECIAL_POSITION + 1 + self::FULL_DISTANCES - self::END_POSITION_MODEL_INDEX;

    private const int LENGTH_CHOICE = 0;

    private const int LENGTH_CHOICE_2 = 1;

    private const int LENGTH_LOW = 2;

    private const int LENGTH_MID = self::LENGTH_LOW + (8 << self::MAX_POSITION_BITS);

    private const int LENGTH_HIGH = self::LENGTH_MID + (8 << self::MAX_POSITION_BITS);

    private const int MATCH_LENGTH = self::ALIGN + (1 << self::ALIGN_BITS);

    private const int REP_LENGTH = self::MATCH_LENGTH + self::LENGTH_HIGH + 256;

    private const int LITERAL = self::REP_LENGTH + self::LENGTH_HIGH + 256;

    /**
     * @param  int<0, max>  $uncompressedSize
     * @param  int<0, 8>  $lc  literal context bits
     * @param  int<0, 4>  $lp  literal position bits
     * @param  int<0, 4>  $pb  position bits
     *
     * @throws RuntimeException when the stream is corrupt or does not decode to exactly $uncompressedSize bytes
     */
    public static function decode(string $compressed, int $uncompressedSize, int $lc = 3, int $lp = 0, int $pb = 2): string
    {
        $inputLength = mb_strlen($compressed, '8bit');

        if ($inputLength < 5 || $compressed[0] !== "\0" || mb_substr($compressed, 1, 4, '8bit') === "\xFF\xFF\xFF\xFF") {
            throw new RuntimeException('The LZMA stream does not start with a range coder header.');
        }

        $input = $compressed . str_repeat("\0", self::INPUT_PADDING);
        $inputPosition = 5;
        $range = 0xFFFFFFFF;
        $code = (ord($input[1]) << 24) | (ord($input[2]) << 16) | (ord($input[3]) << 8) | ord($input[4]);

        $probabilities = array_fill(0, self::LITERAL + (0x300 << ($lc + $lp)), self::PROBABILITY_TOTAL >> 1);
        $positionMask = (1 << $pb) - 1;
        $literalPositionMask = (1 << $lp) - 1;
        $literalShift = 8 - $lc;

        $output = '';
        $position = 0;
        $previousByte = 0;
        $state = 0;
        $rep0 = $rep1 = $rep2 = $rep3 = 0;

        while ($position < $uncompressedSize || $code !== 0) {
            if ($inputPosition > $inputLength) {
                throw new RuntimeException('The LZMA stream is truncated.');
            }

            $positionState = $position & $positionMask;

            $index = self::IS_MATCH + ($state << self::MAX_POSITION_BITS) + $positionState;
            $probability = $probabilities[$index];
            $bound = ($range >> self::PROBABILITY_BITS) * $probability;

            if ($code < $bound) {
                $range = $bound;
                $probabilities[$index] = $probability + ((self::PROBABILITY_TOTAL - $probability) >> self::MOVE_BITS);
                $isMatch = false;
            } else {
                $range -= $bound;
                $code -= $bound;
                $probabilities[$index] = $probability - ($probability >> self::MOVE_BITS);
                $isMatch = true;
            }

            if ($range < self::TOP_VALUE) {
                $range <<= 8;
                $code = (($code << 8) | ord($input[$inputPosition++])) & 0xFFFFFFFF;
            }

            if (! $isMatch) {
                if ($position === $uncompressedSize) {
                    throw new RuntimeException('The LZMA stream decodes to more data than expected.');
                }

                $base = self::LITERAL + 0x300 * ((($position & $literalPositionMask) << $lc) + ($previousByte >> $literalShift));
                $symbol = 1;

                if ($state >= 7) {
                    $matchByte = ord($output[$position - $rep0 - 1]);

                    do {
                        $matchBit = ($matchByte >> 7) & 1;
                        $matchByte <<= 1;

                        $index = $base + ((1 + $matchBit) << 8) + $symbol;
                        $probability = $probabilities[$index];
                        $bound = ($range >> self::PROBABILITY_BITS) * $probability;

                        if ($code < $bound) {
                            $range = $bound;
                            $probabilities[$index] = $probability + ((self::PROBABILITY_TOTAL - $probability) >> self::MOVE_BITS);
                            $bit = 0;
                        } else {
                            $range -= $bound;
                            $code -= $bound;
                            $probabilities[$index] = $probability - ($probability >> self::MOVE_BITS);
                            $bit = 1;
                        }

                        if ($range < self::TOP_VALUE) {
                            $range <<= 8;
                            $code = (($code << 8) | ord($input[$inputPosition++])) & 0xFFFFFFFF;
                        }

                        $symbol = ($symbol << 1) | $bit;
                    } while ($matchBit === $bit && $symbol < 0x100);
                }

                while ($symbol < 0x100) {
                    $index = $base + $symbol;
                    $probability = $probabilities[$index];
                    $bound = ($range >> self::PROBABILITY_BITS) * $probability;

                    if ($code < $bound) {
                        $range = $bound;
                        $probabilities[$index] = $probability + ((self::PROBABILITY_TOTAL - $probability) >> self::MOVE_BITS);
                        $symbol <<= 1;
                    } else {
                        $range -= $bound;
                        $code -= $bound;
                        $probabilities[$index] = $probability - ($probability >> self::MOVE_BITS);
                        $symbol = ($symbol << 1) | 1;
                    }

                    if ($range < self::TOP_VALUE) {
                        $range <<= 8;
                        $code = (($code << 8) | ord($input[$inputPosition++])) & 0xFFFFFFFF;
                    }
                }

                $previousByte = $symbol - 0x100;
                $output .= chr($previousByte);
                $position++;
                $state = $state < 4 ? 0 : ($state < 10 ? $state - 3 : $state - 6);

                continue;
            }

            $index = self::IS_REP + $state;
            $probability = $probabilities[$index];
            $bound = ($range >> self::PROBABILITY_BITS) * $probability;

            if ($code < $bound) {
                $range = $bound;
                $probabilities[$index] = $probability + ((self::PROBABILITY_TOTAL - $probability) >> self::MOVE_BITS);
                $isRep = false;
            } else {
                $range -= $bound;
                $code -= $bound;
                $probabilities[$index] = $probability - ($probability >> self::MOVE_BITS);
                $isRep = true;
            }

            if ($range < self::TOP_VALUE) {
                $range <<= 8;
                $code = (($code << 8) | ord($input[$inputPosition++])) & 0xFFFFFFFF;
            }

            $length = 0;

            if (! $isRep) {
                $rep3 = $rep2;
                $rep2 = $rep1;
                $rep1 = $rep0;
                $lengthCoder = self::MATCH_LENGTH;
                $state = $state < 7 ? 7 : 10;
            } else {
                $lengthCoder = self::REP_LENGTH;

                $index = self::IS_REP_G0 + $state;
                $probability = $probabilities[$index];
                $bound = ($range >> self::PROBABILITY_BITS) * $probability;

                if ($code < $bound) {
                    $range = $bound;
                    $probabilities[$index] = $probability + ((self::PROBABILITY_TOTAL - $probability) >> self::MOVE_BITS);
                    $isRep0 = true;
                } else {
                    $range -= $bound;
                    $code -= $bound;
                    $probabilities[$index] = $probability - ($probability >> self::MOVE_BITS);
                    $isRep0 = false;
                }

                if ($range < self::TOP_VALUE) {
                    $range <<= 8;
                    $code = (($code << 8) | ord($input[$inputPosition++])) & 0xFFFFFFFF;
                }

                if ($isRep0) {
                    $index = self::IS_REP0_LONG + ($state << self::MAX_POSITION_BITS) + $positionState;
                    $probability = $probabilities[$index];
                    $bound = ($range >> self::PROBABILITY_BITS) * $probability;

                    if ($code < $bound) {
                        $range = $bound;
                        $probabilities[$index] = $probability + ((self::PROBABILITY_TOTAL - $probability) >> self::MOVE_BITS);
                        $length = 1;
                        $state = $state < 7 ? 9 : 11;
                    } else {
                        $range -= $bound;
                        $code -= $bound;
                        $probabilities[$index] = $probability - ($probability >> self::MOVE_BITS);
                        $state = $state < 7 ? 8 : 11;
                    }

                    if ($range < self::TOP_VALUE) {
                        $range <<= 8;
                        $code = (($code << 8) | ord($input[$inputPosition++])) & 0xFFFFFFFF;
                    }
                } else {
                    $index = self::IS_REP_G1 + $state;
                    $probability = $probabilities[$index];
                    $bound = ($range >> self::PROBABILITY_BITS) * $probability;

                    if ($code < $bound) {
                        $range = $bound;
                        $probabilities[$index] = $probability + ((self::PROBABILITY_TOTAL - $probability) >> self::MOVE_BITS);
                        $isRep1 = true;
                    } else {
                        $range -= $bound;
                        $code -= $bound;
                        $probabilities[$index] = $probability - ($probability >> self::MOVE_BITS);
                        $isRep1 = false;
                    }

                    if ($range < self::TOP_VALUE) {
                        $range <<= 8;
                        $code = (($code << 8) | ord($input[$inputPosition++])) & 0xFFFFFFFF;
                    }

                    if ($isRep1) {
                        $distance = $rep1;
                    } else {
                        $index = self::IS_REP_G2 + $state;
                        $probability = $probabilities[$index];
                        $bound = ($range >> self::PROBABILITY_BITS) * $probability;

                        if ($code < $bound) {
                            $range = $bound;
                            $probabilities[$index] = $probability + ((self::PROBABILITY_TOTAL - $probability) >> self::MOVE_BITS);
                            $distance = $rep2;
                        } else {
                            $range -= $bound;
                            $code -= $bound;
                            $probabilities[$index] = $probability - ($probability >> self::MOVE_BITS);
                            $distance = $rep3;
                            $rep3 = $rep2;
                        }

                        if ($range < self::TOP_VALUE) {
                            $range <<= 8;
                            $code = (($code << 8) | ord($input[$inputPosition++])) & 0xFFFFFFFF;
                        }

                        $rep2 = $rep1;
                    }

                    $rep1 = $rep0;
                    $rep0 = $distance;
                    $state = $state < 7 ? 8 : 11;
                }
            }

            if ($length === 0) {
                $index = $lengthCoder + self::LENGTH_CHOICE;
                $probability = $probabilities[$index];
                $bound = ($range >> self::PROBABILITY_BITS) * $probability;

                if ($code < $bound) {
                    $range = $bound;
                    $probabilities[$index] = $probability + ((self::PROBABILITY_TOTAL - $probability) >> self::MOVE_BITS);
                    $base = $lengthCoder + self::LENGTH_LOW + ($positionState << 3);
                    $treeBits = 3;
                    $length = 2;
                } else {
                    $range -= $bound;
                    $code -= $bound;
                    $probabilities[$index] = $probability - ($probability >> self::MOVE_BITS);

                    if ($range < self::TOP_VALUE) {
                        $range <<= 8;
                        $code = (($code << 8) | ord($input[$inputPosition++])) & 0xFFFFFFFF;
                    }

                    $index = $lengthCoder + self::LENGTH_CHOICE_2;
                    $probability = $probabilities[$index];
                    $bound = ($range >> self::PROBABILITY_BITS) * $probability;

                    if ($code < $bound) {
                        $range = $bound;
                        $probabilities[$index] = $probability + ((self::PROBABILITY_TOTAL - $probability) >> self::MOVE_BITS);
                        $base = $lengthCoder + self::LENGTH_MID + ($positionState << 3);
                        $treeBits = 3;
                        $length = 10;
                    } else {
                        $range -= $bound;
                        $code -= $bound;
                        $probabilities[$index] = $probability - ($probability >> self::MOVE_BITS);
                        $base = $lengthCoder + self::LENGTH_HIGH;
                        $treeBits = 8;
                        $length = 18;
                    }
                }

                if ($range < self::TOP_VALUE) {
                    $range <<= 8;
                    $code = (($code << 8) | ord($input[$inputPosition++])) & 0xFFFFFFFF;
                }

                $symbol = 1;

                for ($treeBit = 0; $treeBit < $treeBits; $treeBit++) {
                    $index = $base + $symbol;
                    $probability = $probabilities[$index];
                    $bound = ($range >> self::PROBABILITY_BITS) * $probability;

                    if ($code < $bound) {
                        $range = $bound;
                        $probabilities[$index] = $probability + ((self::PROBABILITY_TOTAL - $probability) >> self::MOVE_BITS);
                        $symbol <<= 1;
                    } else {
                        $range -= $bound;
                        $code -= $bound;
                        $probabilities[$index] = $probability - ($probability >> self::MOVE_BITS);
                        $symbol = ($symbol << 1) | 1;
                    }

                    if ($range < self::TOP_VALUE) {
                        $range <<= 8;
                        $code = (($code << 8) | ord($input[$inputPosition++])) & 0xFFFFFFFF;
                    }
                }

                $length += $symbol - (1 << $treeBits);

                if ($lengthCoder === self::MATCH_LENGTH) {
                    $base = self::POSITION_SLOT + (min($length - 2, 3) << 6);
                    $slot = 1;

                    for ($slotBit = 0; $slotBit < 6; $slotBit++) {
                        $index = $base + $slot;
                        $probability = $probabilities[$index];
                        $bound = ($range >> self::PROBABILITY_BITS) * $probability;

                        if ($code < $bound) {
                            $range = $bound;
                            $probabilities[$index] = $probability + ((self::PROBABILITY_TOTAL - $probability) >> self::MOVE_BITS);
                            $slot <<= 1;
                        } else {
                            $range -= $bound;
                            $code -= $bound;
                            $probabilities[$index] = $probability - ($probability >> self::MOVE_BITS);
                            $slot = ($slot << 1) | 1;
                        }

                        if ($range < self::TOP_VALUE) {
                            $range <<= 8;
                            $code = (($code << 8) | ord($input[$inputPosition++])) & 0xFFFFFFFF;
                        }
                    }

                    $slot -= 64;
                    $rep0 = $slot;

                    if ($slot >= 4) {
                        $reverseBits = ($slot >> 1) - 1;
                        $rep0 = (2 | ($slot & 1)) << $reverseBits;

                        if ($slot < self::END_POSITION_MODEL_INDEX) {
                            $base = self::SPECIAL_POSITION + $rep0 - $slot;
                        } else {
                            for ($directBits = $reverseBits - self::ALIGN_BITS; $directBits > 0; $directBits--) {
                                // A bit of probability one half: the code is above the halved range or not.
                                $range >>= 1;
                                $bit = $code >= $range ? 1 : 0;
                                $code -= $range & -$bit;
                                $rep0 += $bit << ($directBits - 1 + self::ALIGN_BITS);

                                if ($range < self::TOP_VALUE) {
                                    $range <<= 8;
                                    $code = (($code << 8) | ord($input[$inputPosition++])) & 0xFFFFFFFF;
                                }
                            }

                            $base = self::ALIGN;
                            $reverseBits = self::ALIGN_BITS;
                        }

                        $node = 1;

                        for ($reverseBit = 0; $reverseBit < $reverseBits; $reverseBit++) {
                            $index = $base + $node;
                            $probability = $probabilities[$index];
                            $bound = ($range >> self::PROBABILITY_BITS) * $probability;

                            if ($code < $bound) {
                                $range = $bound;
                                $probabilities[$index] = $probability + ((self::PROBABILITY_TOTAL - $probability) >> self::MOVE_BITS);
                                $node <<= 1;
                            } else {
                                $range -= $bound;
                                $code -= $bound;
                                $probabilities[$index] = $probability - ($probability >> self::MOVE_BITS);
                                $node = ($node << 1) | 1;
                                $rep0 += 1 << $reverseBit;
                            }

                            if ($range < self::TOP_VALUE) {
                                $range <<= 8;
                                $code = (($code << 8) | ord($input[$inputPosition++])) & 0xFFFFFFFF;
                            }
                        }
                    }

                    if ($rep0 === self::END_MARKER) {
                        break;
                    }
                }
            }

            if ($rep0 >= $position) {
                throw new RuntimeException('The LZMA stream refers to data before its start.');
            }

            if ($length > $uncompressedSize - $position) {
                throw new RuntimeException('The LZMA stream decodes to more data than expected.');
            }

            $matchDistance = $rep0 + 1;
            $matched = mb_substr($output, $position - $matchDistance, min($length, $matchDistance), '8bit');
            $output .= $length <= $matchDistance ? $matched : mb_substr(str_repeat($matched, intdiv($length, $matchDistance) + 1), 0, $length, '8bit');
            $position += $length;
            $previousByte = ord($output[$position - 1]);
        }

        if ($position !== $uncompressedSize || $code !== 0 || $inputPosition !== $inputLength) {
            throw new RuntimeException('The LZMA stream does not end where its data does.');
        }

        return $output;
    }
}
