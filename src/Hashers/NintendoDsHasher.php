<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Hashers;

use Boquizo\RaHasher\GameHasher;
use Override;
use SplFileInfo;
use SplFileObject;

/**
 * MD5 of the 0x160 byte header, the ARM9 and ARM7 code and the 0xA00 byte
 * icon and title block (rc_hash_nintendo_ds). A SuperCard header is skipped.
 */
final readonly class NintendoDsHasher implements GameHasher
{
    private const int HEADER_SIZE = 512;

    private const int HASHED_HEADER_SIZE = 0x160;

    private const int ICON_SIZE = 0xA00;

    private const int MAX_CODE_SIZE = 16 * 1024 * 1024;

    #[Override]
    public function hash(SplFileInfo $file): ?string
    {
        $stream = $file->openFile('rb');
        $offset = 0;
        $header = (string) $stream->fread(self::HEADER_SIZE);

        if ($this->hasSuperCardHeader($header)) {
            $offset = self::HEADER_SIZE;
            $header = (string) $stream->fread(self::HEADER_SIZE);
        }

        $headerSize = mb_strlen($header, '8bit');

        if ($headerSize < self::HEADER_SIZE) {
            return null;
        }

        /** @var array{arm9Address: int, arm9Size: int, arm7Address: int, arm7Size: int} $code */
        $code = unpack('V1arm9Address/x8/V1arm9Size/V1arm7Address/x8/V1arm7Size', $header, 0x20);
        /** @var array{icon: int} $icon */
        $icon = unpack('V1icon', $header, 0x68);

        $codeSize = $code['arm9Size'] + $code['arm7Size'];

        if ($codeSize > self::MAX_CODE_SIZE) {
            return null;
        }

        $arm9Position = $offset + $code['arm9Address'];
        $arm7Position = $offset + $code['arm7Address'];
        $iconPosition = $offset + $icon['icon'];

        $hashedHeader = mb_substr($header, 0, self::HASHED_HEADER_SIZE, '8bit');
        $arm9 = $this->read($stream, $arm9Position, $code['arm9Size']);
        $arm7 = $this->read($stream, $arm7Position, $code['arm7Size']);
        $iconData = $this->read($stream, $iconPosition, self::ICON_SIZE);
        $iconBlock = mb_str_pad($iconData, self::ICON_SIZE, "\0", STR_PAD_RIGHT, '8bit');

        $context = hash_init('md5');
        hash_update($context, $hashedHeader);
        hash_update($context, $arm9);
        hash_update($context, $arm7);
        hash_update($context, $iconBlock);

        return hash_final($context);
    }

    private function hasSuperCardHeader(string $header): bool
    {
        $startsLikeSuperCard = str_starts_with($header, "\x2E\x00\x00\xEA");
        $superCardMark = mb_substr($header, 0xB0, 4, '8bit');

        return $startsLikeSuperCard && $superCardMark === "\x44\x46\x96\x00";
    }

    private function read(SplFileObject $stream, int $position, int $length): string
    {
        if ($length === 0) {
            return '';
        }

        $stream->fseek($position);

        return (string) $stream->fread($length);
    }
}
