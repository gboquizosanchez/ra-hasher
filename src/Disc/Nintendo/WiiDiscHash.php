<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Disc\Nintendo;

use HashContext;
use RuntimeException;

/**
 * Feeds a hash with what identifies a Wii disc, like rcheevos'
 * rc_hash_wii_disc: the main header, the region code and, for every
 * partition but the update one, its title metadata (TMD) and either the
 * first 1024 encrypted clusters of its data or, on a decrypted disc, its
 * header, apploader and DOL like a GameCube disc.
 *
 * rcheevos seeks to the data offset of a partition without adding the
 * partition offset (it is relative to the partition on a real disc), and so
 * does this port. It also reads the TMD and data offsets as signed 32 bit
 * words: from 0x80000000 on they point 16 GiB before the intended byte. A
 * range before the start or past the end of the disc throws instead of
 * hashing whatever rcheevos' buffers held, so such a disc is left to RAHasher.
 */
final readonly class WiiDiscHash
{
    private const int MAIN_HEADER_SIZE = 0x80;

    private const int ENCRYPTION_FLAG_OFFSET = 0x61;

    private const int REGION_CODE_OFFSET = 0x4E000;

    private const int PARTITION_INFO_OFFSET = 0x40000;

    private const int PARTITION_GROUPS = 4;

    private const int MAX_PARTITIONS = 16;

    private const int UPDATE_PARTITION = 1;

    private const int TMD_FIELDS_OFFSET = 0x2A4;

    private const int DATA_FIELDS_OFFSET = 0x2B8;

    /** The bytes of a cluster rcheevos hashes, past its 0x400 bytes of hashes. */
    private const int CLUSTER_DATA_SIZE = 0x7C00;

    private const int CLUSTER_SIZE = 0x8000;

    private const int CLUSTER_HASHES_SIZE = 0x400;

    private const int MAX_CLUSTERS = 1024;

    /**
     * @throws RuntimeException when the partition table is empty or too big, or
     *                          the data lies outside the disc
     */
    public static function append(HashContext $context, NintendoDisc $disc): void
    {
        $isEncrypted = $disc->read(self::ENCRYPTION_FLAG_OFFSET, 1) === "\0";

        hash_update($context, $disc->read(0, self::MAIN_HEADER_SIZE));
        hash_update($context, $disc->read(self::REGION_CODE_OFFSET, 4));

        foreach (self::partitions($disc) as ['offset' => $partitionOffset, 'type' => $type]) {
            if ($type === self::UPDATE_PARTITION) {
                continue;
            }

            /** @var array{size: int, offset: int} $tmd */
            $tmd = unpack('Nsize/Noffset', $disc->read($partitionOffset + self::TMD_FIELDS_OFFSET, 8));
            $tmdSize = min($tmd['size'], self::CLUSTER_DATA_SIZE);
            hash_update($context, $disc->read($partitionOffset + self::signedWords($tmd['offset']), $tmdSize));

            /** @var array{offset: int, size: int} $data */
            $data = unpack('Noffset/Nsize', $disc->read($partitionOffset + self::DATA_FIELDS_OFFSET, 8));

            if ($isEncrypted) {
                self::appendClusters($context, $disc, self::signedWords($data['offset']), $data['size'] << 2);

                continue;
            }

            PartitionHash::append($context, $disc, ($data['offset'] << 2) & 0xFFFFFFFF, 2);
        }
    }

    /**
     * The offset and type of every partition, group after group.
     *
     * @return list<array{offset: int, type: int}>
     */
    private static function partitions(NintendoDisc $disc): array
    {
        /** @var array<int, int> $groups */
        $groups = unpack('N' . self::PARTITION_GROUPS * 2, $disc->read(self::PARTITION_INFO_OFFSET, self::PARTITION_GROUPS * 8));
        $groups = array_chunk(array_values($groups), 2);
        $total = array_sum(array_column($groups, 0));

        if ($total === 0) {
            throw new RuntimeException('The Wii disc has no partitions.');
        }

        if ($total > self::MAX_PARTITIONS) {
            throw new RuntimeException('The Wii disc has too many partitions.');
        }

        $partitions = [];

        foreach ($groups as [$count, $tableOffset]) {
            if ($count === 0) {
                continue;
            }

            /** @var array<int, int> $entries */
            $entries = unpack('N' . $count * 2, $disc->read($tableOffset << 2, $count * 8));

            foreach (array_chunk(array_values($entries), 2) as [$offset, $type]) {
                $partitions[] = ['offset' => $offset << 2, 'type' => $type];
            }
        }

        return $partitions;
    }

    /** Hashes the data of the first clusters, past their hashes, at most 1024 of them. */
    private static function appendClusters(HashContext $context, NintendoDisc $disc, int $dataOffset, int $dataSize): void
    {
        $clusters = min(intdiv($dataSize, self::CLUSTER_SIZE), self::MAX_CLUSTERS);

        for ($cluster = 0; $cluster < $clusters; $cluster++) {
            $offset = $dataOffset + $cluster * self::CLUSTER_SIZE + self::CLUSTER_HASHES_SIZE;
            hash_update($context, $disc->read($offset, self::CLUSTER_DATA_SIZE));
        }
    }

    /**
     * The bytes a word count stands for, read as a signed 32 bit word and
     * widened like rcheevos does.
     */
    private static function signedWords(int $words): int
    {
        return ($words << 2) - ($words >= 0x80000000 ? 1 << 34 : 0);
    }
}
