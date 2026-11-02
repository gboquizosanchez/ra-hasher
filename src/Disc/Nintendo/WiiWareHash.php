<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Disc\Nintendo;

use Boquizo\RaHasher\GameHasher;
use HashContext;
use RuntimeException;

/**
 * Feeds a hash with what identifies a WiiWare title (.wad), like rcheevos'
 * rc_hash_wiiware: its title metadata (TMD) and every content, each one
 * rounded up to the AES block and at most 64 MiB. The sections of the file
 * start at 0x40 byte boundaries. The file is read through the same byte
 * ranges as a disc.
 *
 * rcheevos computes the addresses in 32 bits, so they wrap around like
 * there; a content bigger than 4 GiB counts as 64 MiB. It hashes whole
 * buffers even when the file ends before them, with whatever memory they
 * held; a range past the end of the file throws here instead, so such a
 * file is left to RAHasher.
 */
final readonly class WiiWareHash
{
    private const int CERTIFICATE_CHAIN_SIZE_OFFSET = 0x08;

    private const int TICKET_AND_TMD_SIZES_OFFSET = 0x10;

    private const int HEADER_SIZE = 0x40;

    private const int CONTENT_COUNT_OFFSET = 0x1DE;

    /** Where the size of the first content lies in the TMD, every record being 0x24 bytes. */
    private const int CONTENT_SIZE_OFFSET = 0x1E4 + 8;

    private const int CONTENT_RECORD_SIZE = 0x24;

    private const int SECTION_ALIGNMENT = 0x40;

    private const int AES_BLOCK_SIZE = 0x10;

    private const int UINT32_MASK = 0xFFFFFFFF;

    private const int MAX_CHUNK_SIZE = 1024 * 1024;

    /**
     * @throws RuntimeException when the data lies past the end of the file
     */
    public static function append(HashContext $context, NintendoDisc $file): void
    {
        /** @var array{1: int} $certificateChain */
        $certificateChain = unpack('N', $file->read(self::CERTIFICATE_CHAIN_SIZE_OFFSET, 4));

        /** @var array{ticket: int, tmd: int} $sizes */
        $sizes = unpack('Nticket/Ntmd', $file->read(self::TICKET_AND_TMD_SIZES_OFFSET, 8));

        $tmdSize = min(self::align($sizes['tmd'], self::SECTION_ALIGNMENT), GameHasher::MAX_BUFFER_SIZE);
        $tmdStart = (self::HEADER_SIZE + self::align($certificateChain[1], self::SECTION_ALIGNMENT) + self::align($sizes['ticket'], self::SECTION_ALIGNMENT)) & self::UINT32_MASK;
        self::appendRange($context, $file, $tmdStart, $tmdSize);

        /** @var array{1: int} $contentCount */
        $contentCount = unpack('n', $file->read($tmdStart + self::CONTENT_COUNT_OFFSET, 2));
        $contentAddress = ($tmdStart + $tmdSize) & self::UINT32_MASK;

        for ($content = 0; $content < $contentCount[1]; $content++) {
            /** @var array{high: int, low: int} $size */
            $size = unpack('Nhigh/Nlow', $file->read($tmdStart + self::CONTENT_SIZE_OFFSET + $content * self::CONTENT_RECORD_SIZE, 8));
            $contentSize = $size['high'] === 0 ? self::align($size['low'], self::AES_BLOCK_SIZE) : GameHasher::MAX_BUFFER_SIZE;

            self::appendRange($context, $file, $contentAddress, min($contentSize, GameHasher::MAX_BUFFER_SIZE));
            $contentAddress = self::align(($contentAddress + $contentSize) & self::UINT32_MASK, self::SECTION_ALIGNMENT);
        }
    }

    /** Rounds a 32 bit size up to the alignment, wrapping around like rcheevos. */
    private static function align(int $size, int $alignment): int
    {
        return ($size + $alignment - 1) & ~($alignment - 1) & self::UINT32_MASK;
    }

    /** Hashes the bytes of a section 1 MiB at a time. */
    private static function appendRange(HashContext $context, NintendoDisc $file, int $offset, int $size): void
    {
        for ($done = 0; $done < $size; $done += self::MAX_CHUNK_SIZE) {
            hash_update($context, $file->read($offset + $done, min(self::MAX_CHUNK_SIZE, $size - $done)));
        }
    }
}
