<?php

declare(strict_types=1);

namespace Boquizo\RaHasher;

use Boquizo\RaHasher\Runner\CommandResult;
use Boquizo\RaHasher\Runner\CommandRunner;
use Boquizo\RaHasher\Runner\ProcessCommandRunner;
use Closure;
use SplFileInfo;

/**
 * Calculates the RetroAchievements hash of game files: in PHP when the
 * system has a native hasher that can read the file, and with RAHasher
 * otherwise. GameCube games and the Wii containers RAHasher cannot read are
 * converted to ISO with DolphinTool first.
 *
 * @link https://docs.retroachievements.org/developer-docs/game-identification.html
 * @link https://github.com/RetroAchievements/RALibretro/blob/develop/src/RAHasher.cpp
 */
final readonly class RaHasher
{
    public const int GAME_CUBE = 16;

    public const int WII = 19;

    private const int ARCADE = 27;

    /** Wii containers RAHasher cannot read. */
    private const array WII_DOLPHIN_CONTAINERS = ['wbfs', 'rvz', 'wia', 'gcz', 'ciso'];

    private string $temporaryPath;

    /**
     * @param  string  $raHasherPath  RAHasher executable
     * @param  string  $dolphinToolPath  DolphinTool executable
     * @param  string|null  $temporaryPath  folder for the converted ISO images and the temporary copies, the system one by default
     */
    public function __construct(
        private string $raHasherPath,
        private string $dolphinToolPath,
        private CommandRunner $runner = new ProcessCommandRunner(),
        ?string $temporaryPath = null,
    ) {
        $this->temporaryPath = $temporaryPath ?? sys_get_temp_dir();
    }

    /** The second and later parts of a split WBFS image (.wbf1 to .wbf9), read along with its .wbfs file. */
    public static function isWbfsSplitPart(SplFileInfo $file): bool
    {
        return (bool) preg_match('/^wbf[1-9]$/i', $file->getExtension());
    }

    /** Returns an empty string when the file cannot be hashed. */
    public function hash(SplFileInfo $file, int $systemId): string
    {
        return $this->hashMany([$file], $systemId)[0];
    }

    /**
     * Hashes the files natively when possible, converts the ones DolphinTool
     * has to convert one by one and runs RAHasher on the rest at the same time.
     *
     * @param  list<SplFileInfo>  $files
     * @param  (Closure(SplFileInfo, string): void)|null  $onHashed  called as soon as the hash of each file is known
     * @return list<string> the hashes in the order of the files, empty for the files that cannot be hashed
     */
    public function hashMany(array $files, int $systemId, ?Closure $onHashed = null): array
    {
        $hasher = GameHasherRegistry::for($systemId);
        $hashes = [];
        $leftToRaHasher = [];

        foreach ($files as $index => $file) {
            $hash = $hasher?->hash($file);

            if ($hash === null && $this->convertsWithDolphinTool($file, $systemId)) {
                $hash = $this->hashConvertedToIso($file, $systemId);
            }

            if ($hash === null) {
                $leftToRaHasher[$index] = $file;

                continue;
            }

            $hashes[$index] = $hash;
            $onHashed?->__invoke($file, $hash);
        }

        $raHasherHashes = $this->raHasher(array_values($leftToRaHasher), $systemId);

        foreach (array_keys($leftToRaHasher) as $position => $index) {
            $hashes[$index] = $raHasherHashes[$position];
            $onHashed?->__invoke($leftToRaHasher[$index], $raHasherHashes[$position]);
        }

        ksort($hashes);

        return array_values($hashes);
    }

    /**
     * Hashes the files with RAHasher at the same time, without the native
     * hashers. RAHasher cannot open paths with non ASCII characters, so those
     * files are hashed through a temporary copy, except for arcade, whose hash
     * is the file name.
     *
     * @param  list<SplFileInfo>  $files
     * @return list<string> the hashes in the order of the files, empty for the files RAHasher cannot hash
     */
    public function raHasher(array $files, int $systemId): array
    {
        if ($files === []) {
            return [];
        }

        $targets = array_map(fn (SplFileInfo $file): string => $this->readableByRaHasher($file, $systemId), $files);
        $commands = array_map(fn (string $path): string => sprintf('%s %d "%s"', $this->raHasherPath, $systemId, $path), $targets);

        try {
            $results = $this->runner->run($commands);
        } finally {
            foreach ($targets as $index => $target) {
                if ($target !== $files[$index]->getPathname()) {
                    unlink($target);
                }
            }
        }

        return array_map(self::hashFrom(...), $results);
    }

    private static function hashFrom(CommandResult $result): string
    {
        $lines = explode("\n", mb_trim($result->output));
        $hash = mb_strtolower(mb_trim((string) end($lines)));

        return preg_match('/^[a-f0-9]{32}$/', $hash) ? $hash : '';
    }

    private function convertsWithDolphinTool(SplFileInfo $file, int $systemId): bool
    {
        $isWiiContainer = in_array(mb_strtolower($file->getExtension()), self::WII_DOLPHIN_CONTAINERS, strict: true);

        return $systemId === self::GAME_CUBE || ($systemId === self::WII && $isWiiContainer);
    }

    private function hashConvertedToIso(SplFileInfo $file, int $systemId): string
    {
        $iso = $this->temporaryFile('iso');
        $convert = sprintf(
            '%s convert --user="%s" --format=iso --input="%s" --output="%s"',
            $this->dolphinToolPath,
            $this->dolphinUserPath(),
            $file->getPathname(),
            $iso,
        );

        try {
            $this->runner->run([$convert]);

            return is_file($iso) ? $this->raHasher([new SplFileInfo($iso)], $systemId)[0] : '';
        } finally {
            if (is_file($iso)) {
                unlink($iso);
            }
        }
    }

    /**
     * A DolphinTool user folder with the panic handlers off: converting a WBFS
     * image to ISO trips an assertion (its size is not exact) that otherwise
     * opens a dialog and waits until someone closes it.
     */
    private function dolphinUserPath(): string
    {
        $path = $this->temporaryPath . DIRECTORY_SEPARATOR . 'ra-hasher-dolphin';
        $config = $path . DIRECTORY_SEPARATOR . 'Config';

        if (! is_dir($config)) {
            mkdir($config, recursive: true);
        }

        file_put_contents($config . DIRECTORY_SEPARATOR . 'Dolphin.ini', "[Interface]\nUsePanicHandlers = False\n");

        return $path;
    }

    private function readableByRaHasher(SplFileInfo $file, int $systemId): string
    {
        $path = $file->getPathname();

        if ($systemId === self::ARCADE || ! preg_match('/[^\x00-\x7F]/', $path)) {
            return $path;
        }

        $copy = $this->temporaryFile($file->getExtension());
        copy($path, $copy);

        return $copy;
    }

    private function temporaryFile(string $extension): string
    {
        return sprintf('%s%sra-hasher-%s.%s', $this->temporaryPath, DIRECTORY_SEPARATOR, bin2hex(random_bytes(8)), $extension);
    }
}
