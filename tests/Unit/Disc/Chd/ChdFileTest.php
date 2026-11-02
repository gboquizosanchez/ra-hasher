<?php

declare(strict_types=1);

use Boquizo\RaHasher\Compression\LzmaDecoder;
use Boquizo\RaHasher\Disc\Chd\ChdFile;
use Boquizo\RaHasher\Disc\Chd\ChdMapEntry;
use Boquizo\RaHasher\Disc\Chd\Crc16;

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'chd-' . uniqid();
    mkdir($this->directory);
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: []);
    rmdir($this->directory);
});

function lzmaHunk(): string
{
    return (string) file_get_contents(dirname(__DIR__, 3) . '/Fixtures/Lzma/literals.lzma');
}

/** A zstd frame with a 64 KiB window that stores the data in a raw block. */
function storedZstdFrame(string $data): string
{
    return "\x28\xB5\x2F\xFD\x00\x30" . mb_substr(pack('V', 1 | (mb_strlen($data, '8bit') << 3)), 0, 3, '8bit') . $data;
}

it('reads the geometry of the image', function (string $name, int $hunkBytes, int $unitBytes, int $hunkCount, int $unitCount): void {
    $chd = ChdFile::open(new SplFileInfo(chdFixturePath($name)));

    expect([$chd->hunkBytes(), $chd->unitBytes(), $chd->hunkCount(), $chd->unitCount()])->toBe([$hunkBytes, $unitBytes, $hunkCount, $unitCount]);
})->with([
    'cd image' => ['audio-data-audio', 19584, 2448, 10, 80],
    'image without compression' => ['raw-none', 4096, 2048, 6, 11],
]);

it('decodes the hunks of real images', function (string $name, array $md5s): void {
    $chd = ChdFile::open(chdFixturePath($name));

    expect(array_map(fn (int $hunk): string => md5($chd->readHunk($hunk)), array_keys($md5s)))->toBe(array_values($md5s));
})->with([
    'cd sectors with lzma' => ['mode1-cdlz', ['2e953b78690e1f32be126f5ae6cd2559', '8be172aedd75f3ebc5ed9738b4ac05ab']],
    'cd sectors with zlib' => ['mode1-cdzl', ['2e953b78690e1f32be126f5ae6cd2559', '8be172aedd75f3ebc5ed9738b4ac05ab']],
    'mode 2 cd sectors' => ['mode2', [
        '2c0f34a24707ea04cd4fbd6440e3e128', 'd56a4c1ef6a0a899308f22796b8b620c', 'b53de66357523a402c10be350f89fa65',
        '5b3b99ee0d0fed01a02ea19feaa402eb', '343334410da4376ae7e4cbd16313859c', 'd5aea0d670fb287b925aa8327fea9258',
        'aa93a4a935609100a95630a9b922bc5c', 'e1057c32ad06f7362adea15f79b4faeb', 'ea12cd37d12f4db9ae48287d67e8bcfe',
        '8b20544a599e75c8dd0cb001ec1d5310', '2ac815a7b2c5340b136a12c7291b7d5c', 'bf5cd57e896e08865a28f4d952a8338e',
    ]],
    'cd audio with flac, and a copy of a flac hunk' => ['audio-data-audio', [
        0 => '5c292f793ddf1e27489e70938d915bff', 1 => '6542c1f2d7f3421d00e522a1e4d7954f', 2 => '96ba8dee14b65f64676de781c2e51278',
        7 => '44202038d818f3f959aab348358c84df', 8 => '44202038d818f3f959aab348358c84df', 9 => '928a216987cdcab211fe49aa0c5301c9',
    ]],
    'data track between audio tracks' => ['audio-data-audio', [
        3 => '115e86e2bd6c0a529f9ee99089fee9b2', 4 => '48c4e30a5b29c77a32be3a9cc226f5ef',
        5 => '15e41fe48370fe4117b3995b0eca4c2e', 6 => 'fbd87c0333105beade6e1b7de207d1a9',
    ]],
    'uncompressed and zero hunks' => ['raw-none', [
        '5212a235c1357f5ff645e8fef4b20db1', '398d783f4755c9216475fb4b7cb8496f', '857b9d08e112f12228d80cfd4945e1a4',
        '5212a235c1357f5ff645e8fef4b20db1', '620f0b67a91f7f74151bc5be745b7110', '428b8efba752667ee50f3893d50cad91',
    ]],
]);

it('decodes zlib, lzma, uncompressed and copied hunks', function (): void {
    $zlibHunk = str_repeat('zlib hunk ', 30);
    $lzmaHunk = LzmaDecoder::decode(lzmaHunk(), SYNTHETIC_CHD_HUNK_BYTES);
    $uncompressedHunk = random_bytes(SYNTHETIC_CHD_HUNK_BYTES);
    $chd = ChdFile::open(chdOnDisk($this->directory, syntheticChd([
        [0, gzdeflate($zlibHunk), Crc16::compute($zlibHunk)],
        [1, lzmaHunk(), Crc16::compute($lzmaHunk)],
        [ChdMapEntry::UNCOMPRESSED, $uncompressedHunk, Crc16::compute($uncompressedHunk)],
        [ChdMapEntry::SELF, '', 0],
    ])));

    expect(array_map($chd->readHunk(...), [0, 1, 2, 3]))->toBe([$zlibHunk, $lzmaHunk, $uncompressedHunk, $zlibHunk])
        ->and(md5($lzmaHunk))->toBe('693cc569baa071a8adbdd268a9c078b4');
});

it('decodes zstd hunks and cd hunks whose sectors and subcode are compressed with zstd', function (): void {
    $sector = str_repeat('sector ', 336);
    $subcode = str_repeat('subcode ', 12);
    $cdHunk = "\x00" . pack('n', mb_strlen(storedZstdFrame($sector), '8bit')) . storedZstdFrame($sector) . storedZstdFrame($subcode);
    $chd = ChdFile::open(chdOnDisk($this->directory, syntheticChd([
        [0, storedZstdFrame(str_repeat('z', 2448)), Crc16::compute(str_repeat('z', 2448))],
        [1, $cdHunk, Crc16::compute($sector . $subcode)],
    ], ['zstd', 'cdzs'], hunkBytes: 2448)));

    expect([$chd->readHunk(0), $chd->readHunk(1)])->toBe([str_repeat('z', 2448), $sector . $subcode]);
});

it('keeps the last hunk it read', function (): void {
    $hunk = str_repeat('a', SYNTHETIC_CHD_HUNK_BYTES);
    $image = syntheticChd([[ChdMapEntry::UNCOMPRESSED, $hunk, Crc16::compute($hunk)]]);
    $path = chdOnDisk($this->directory, $image);
    $chd = ChdFile::open($path);
    $chd->readHunk(0);

    file_put_contents($path, patchedChd($image, CHD_MAP_FIRST_OFFSET, 'b'));

    expect($chd->readHunk(0))->toBe($hunk);
});

it('rejects an image that is cut after it was opened', function (): void {
    $hunk = str_repeat('a', 20000);
    $image = syntheticChd([[ChdMapEntry::UNCOMPRESSED, $hunk, Crc16::compute($hunk)]], hunkBytes: 20000);
    $path = chdOnDisk($this->directory, $image);
    $chd = ChdFile::open($path);

    file_put_contents($path, mb_substr($image, 0, CHD_MAP_FIRST_OFFSET + 10, '8bit'));

    expect(fn (): string => $chd->readHunk(0))->toThrow(RuntimeException::class, 'ends before its data does');
});

it('reads metadata by tag and index', function (string $tag, int $index, ?string $expected): void {
    $chd = ChdFile::open(chdFixturePath('audio-data-audio'));

    expect($chd->metadata($tag, $index))->toBe($expected);
})->with([
    'first track' => ['CHT2', 0, 'TRACK:1 TYPE:AUDIO SUBTYPE:NONE FRAMES:24 PREGAP:0 PGTYPE:MODE1 PGSUB:NONE POSTGAP:0'],
    'last track' => ['CHT2', 2, 'TRACK:3 TYPE:AUDIO SUBTYPE:NONE FRAMES:8 PREGAP:0 PGTYPE:MODE1 PGSUB:NONE POSTGAP:0'],
    'index past the last entry' => ['CHT2', 3, null],
    'absent tag' => ['CHGD', 0, null],
]);

it('reads metadata entries without data and images without metadata', function (): void {
    $withEmptyEntry = ChdFile::open(chdOnDisk($this->directory, syntheticChd([[0, '', 0]], metadata: [['CHT2', 'TRACK:1'], ['EMTY', '']])));
    $withoutMetadata = ChdFile::open(chdFixturePath('raw-none'));

    expect($withEmptyEntry->metadata('EMTY', 0))->toBe('')
        ->and($withEmptyEntry->metadata('CHT2', 0))->toBe('TRACK:1')
        ->and($withoutMetadata->metadata('CHT2', 0))->toBeNull();
});

it('rejects metadata that loops', function (): void {
    $image = syntheticChd([[0, '', 0]], metadata: [['CHT2', 'TRACK:1']]);
    $chd = ChdFile::open(chdOnDisk($this->directory, patchedChd($image, CHD_MAP_FIRST_OFFSET + 8, pack('J', CHD_MAP_FIRST_OFFSET))));

    expect(fn (): ?string => $chd->metadata('CHT2', 1))->toThrow(RuntimeException::class, 'metadata of the CHD image loops');
});

it('rejects files that are not supported chd v5 images', function (Closure $image, string $message): void {
    $path = chdOnDisk($this->directory, $image(syntheticChd([[0, '', 0]])));

    expect(fn (): ChdFile => ChdFile::open($path))->toThrow(RuntimeException::class, $message);
})->with([
    'another format' => [fn (string $image): string => patchedChd($image, 0, 'NotAChd!'), 'not a CHD image'],
    'empty file' => [fn (string $image): string => '', 'not a CHD image'],
    'version 4' => [fn (string $image): string => patchedChd($image, 8, pack('NN', 108, 4)), 'version 4 are not supported'],
    'another header length' => [fn (string $image): string => patchedChd($image, 8, pack('N', 108)), 'header is truncated'],
    'truncated header' => [fn (string $image): string => mb_substr($image, 0, 100, '8bit'), 'header is truncated'],
    'a parent' => [fn (string $image): string => patchedChd($image, 104, "\x01"), 'with a parent are not supported'],
    'hunks of zero bytes' => [fn (string $image): string => patchedChd($image, 56, pack('N', 0)), 'hunks or units of zero bytes'],
    'units of zero bytes' => [fn (string $image): string => patchedChd($image, 60, pack('N', 0)), 'hunks or units of zero bytes'],
    'truncated map' => [fn (string $image): string => mb_substr($image, 0, 130, '8bit'), 'ends before its data does'],
    'logical bytes that overflow the hunk count' => [fn (string $image): string => patchedChd($image, 32, pack('J', PHP_INT_MAX - 10)), 'map of '],
    'the smallest logical bytes' => [fn (string $image): string => patchedChd($image, 32, pack('J', PHP_INT_MIN)), 'no logical data'],
    'logical bytes of zero' => [fn (string $image): string => patchedChd($image, 32, pack('J', 0)), 'no logical data'],
    'a negative map offset' => [fn (string $image): string => patchedChd($image, 40, pack('J', -1)), 'points past the end of its file'],
    'a map offset past the end of the file' => [fn (string $image): string => patchedChd($image, 40, pack('J', 1 << 40)), 'points past the end of its file'],
    'a negative metadata offset' => [fn (string $image): string => patchedChd($image, 48, pack('J', -1)), 'points past the end of its file'],
    'a metadata offset past the end of the file' => [fn (string $image): string => patchedChd($image, 48, pack('J', 1 << 40)), 'points past the end of its file'],
    'hunks of 128 MiB' => [fn (string $image): string => patchedChd($image, 56, pack('N', 128 * 1024 * 1024)), 'hunks of 134217728 bytes are too big'],
    'hunks of 4 GiB less one byte' => [fn (string $image): string => patchedChd($image, 56, pack('N', 0xFFFFFFFF)), 'are too big'],
    'more hunks than a compressed map may hold' => [fn (string $image): string => patchedChd($image, 32, pack('J', 300 * 30_000_000)), 'map of 30000000 hunks is too big'],
    'more hunks than an uncompressed map may hold' => [fn (string $image): string => patchedChd(patchedChd($image, 16, "\0\0\0\0"), 32, pack('J', 300 * 70_000_000)), 'map of 70000000 hunks is too big'],
    'an uncompressed map past the end of the file' => [fn (string $image): string => patchedChd(patchedChd($image, 16, "\0\0\0\0"), 32, pack('J', 300 * 1000)), 'ends before its data does'],
    'a compressed map longer than the file' => [fn (string $image): string => patchedChd($image, 124, pack('N', 0xFFFFFFFF)), 'ends before its data does'],
]);

it('rejects metadata whose entries leave the file', function (string $field, string $bytes, string $message): void {
    $offsets = ['next' => CHD_MAP_FIRST_OFFSET + 8, 'length' => CHD_MAP_FIRST_OFFSET + 4];
    $image = syntheticChd([[0, '', 0]], metadata: [['CHT2', 'TRACK:1']]);
    $chd = ChdFile::open(chdOnDisk($this->directory, patchedChd($image, $offsets[$field], $bytes)));

    expect(fn (): ?string => $chd->metadata('CHT2', 0))->toThrow(RuntimeException::class, $message);
})->with([
    'a negative next entry' => ['next', pack('J', -1), 'ends before its data does'],
    'a next entry past the end of the file' => ['next', pack('J', 1 << 40), 'ends before its data does'],
    'data longer than the file' => ['length', pack('N', 0xFFFFFF), 'ends before its data does'],
]);

it('follows chains of copied hunks without recursing', function (): void {
    $hunk = str_repeat('zlib hunk ', 30);
    $crc = Crc16::compute($hunk);
    $copies = 3000;
    $records = [[0, mb_strlen(gzdeflate($hunk), '8bit'), CHD_MAP_FIRST_OFFSET, $crc]];

    for ($copy = 1; $copy < $copies; $copy++) {
        $records[] = [ChdMapEntry::SELF, 0, $copy - 1, 0];
    }

    $image = syntheticChd([[0, gzdeflate($hunk), $crc]]);
    $mapOffset = mb_strlen($image, '8bit');
    $map = compressedChdMap([0, ChdMapEntry::SELF, ...array_fill(0, $copies - 2, 10)], [[$records[0][1], 16], [$crc, 16], [0, 8]], $records);
    $image = patchedChd(patchedChd($image, 32, pack('J', $copies * SYNTHETIC_CHD_HUNK_BYTES)), 40, pack('J', $mapOffset)) . $map;
    $chd = ChdFile::open(chdOnDisk($this->directory, $image));

    expect($chd->hunkCount())->toBe($copies)
        ->and($chd->readHunk($copies - 1))->toBe($hunk)
        ->and($chd->readHunk(1))->toBe($hunk);
});

it('rejects hunks it cannot decode', function (array $hunk, int $hunkBytes, string $message): void {
    $chd = ChdFile::open(chdOnDisk($this->directory, syntheticChd([$hunk], hunkBytes: $hunkBytes)));

    expect(fn (): string => $chd->readHunk(0))->toThrow(RuntimeException::class, $message);
})->with([
    'a wrong crc' => [[0, gzdeflate(str_repeat('a', 300)), 0x1234], 300, 'Hunk 0 of the CHD image does not match its CRC'],
    'corrupt deflate data' => [[0, 'not deflate data', 0], 300, 'deflate data of the CHD image is corrupt'],
    'deflate data of another size' => [[0, gzdeflate('short'), 0], 300, 'deflate data of the CHD image is corrupt'],
    'corrupt flac data' => [[3, 'flac data', 0], 2448, 'FLAC frame does not start with a sync code'],
    'hunks without whole cd frames' => [[2, 'cd data', 0], 300, 'hunks of 300 bytes do not hold whole CD frames'],
    'a cd hunk shorter than its header' => [[2, "\x01\x00", 0], 2448, 'too short for its header'],
    'a cd hunk shorter than its sectors' => [[2, "\x01\x00\x10abc", 0], 2448, 'shorter than its sectors'],
    'data past the end of the file' => [[ChdMapEntry::UNCOMPRESSED, 'short', 0], 300, 'ends before its data does'],
]);

it('rejects the codecs it does not support', function (string $codec): void {
    $chd = ChdFile::open(chdOnDisk($this->directory, syntheticChd([[0, 'compressed data', 0]], [$codec])));

    expect(fn (): string => $chd->readHunk(0))->toThrow(RuntimeException::class, 'Hunk 0 of the CHD image uses the unsupported codec "' . $codec . '"');
})->with(['flac', 'huff']);

it('rejects the codecs of hunk types without one', function (): void {
    $chd = ChdFile::open(chdOnDisk($this->directory, syntheticChd([[1, 'lzma data', 0]], ['zlib'])));

    expect(fn (): string => $chd->readHunk(0))->toThrow(RuntimeException::class, 'uses the unsupported codec ""');
});

it('has no hunks outside the image', function (): void {
    $chd = ChdFile::open(chdFixturePath('raw-none'));

    expect(fn (): string => $chd->readHunk(6))->toThrow(RuntimeException::class, 'has no hunk 6');
});
