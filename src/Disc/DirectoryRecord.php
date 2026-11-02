<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Disc;

/**
 * A directory record of an ISO 9660 volume: where its file or directory
 * starts and how the rcheevos lookup (rc_cd_find_file_sector) compares it
 * with a name.
 */
final readonly class DirectoryRecord
{
    /**
     * @param  string  $nameField  the bytes of the sector from the start of the name, the name being the first $nameLength of them
     */
    public function __construct(
        public int $sector,
        public int $size,
        public int $nameLength,
        private string $nameField,
    ) {}

    /** Directories are not versioned, so the name has to match exactly. */
    public function isDirectoryNamed(string $name): bool
    {
        return $this->nameLength === mb_strlen($name, '8bit') && $this->startsWithName($name);
    }

    /** A file matches with its name alone or followed by the ";version" suffix. */
    public function isFileNamed(string $name): bool
    {
        $length = mb_strlen($name, '8bit');
        $nameEnd = mb_substr($this->nameField, $length, 1, '8bit');

        return ($this->nameLength === $length || $nameEnd === ';') && $this->startsWithName($name);
    }

    private function startsWithName(string $name): bool
    {
        $field = mb_substr($this->nameField, 0, mb_strlen($name, '8bit'), '8bit');

        return strcasecmp($field, $name) === 0;
    }
}
