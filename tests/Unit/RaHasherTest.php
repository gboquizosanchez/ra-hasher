<?php

declare(strict_types=1);

use Boquizo\RaHasher\RaHasher;
use Boquizo\RaHasher\Runner\CommandResult;

const RA_HASHER_HASH = '1bc674be034e43c96b86487ac69d9293';

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ra-hasher-test-' . uniqid();
    $this->games = $this->directory . DIRECTORY_SEPARATOR . 'games';
    $this->temporary = $this->directory . DIRECTORY_SEPARATOR . 'temporary';
    mkdir($this->games, recursive: true);
    mkdir($this->temporary);
});

afterEach(function (): void {
    removeDirectory($this->directory);
});

function removeDirectory(string $directory): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }

    rmdir($directory);
}

function gameFile(string $directory, string $name, string $contents = 'rom'): SplFileInfo
{
    $path = $directory . DIRECTORY_SEPARATOR . $name;
    file_put_contents($path, $contents);

    return new SplFileInfo($path);
}

function raHasherWith(FakeCommandRunner $runner, string $temporary): RaHasher
{
    return new RaHasher('RAHasher.exe', 'DolphinTool.exe', $runner, $temporary);
}

/** Answers the DolphinTool conversions writing the ISO, or not when it fails, and the RAHasher commands with the hash. */
function dolphinRunner(bool $converts = true): FakeCommandRunner
{
    return new FakeCommandRunner(static function (string $command) use ($converts): CommandResult {
        if (str_starts_with($command, 'DolphinTool.exe') && $converts) {
            preg_match('/--output="([^"]+)"/', $command, $output);
            file_put_contents($output[1], 'converted by dolphin');
        }

        return new CommandResult(RA_HASHER_HASH);
    });
}

/** @return list<string> */
function temporaryFiles(string $temporary): array
{
    return glob($temporary . DIRECTORY_SEPARATOR . 'ra-hasher-*.*') ?: [];
}

it('hashes the systems with a native hasher without running anything', function (): void {
    $runner = FakeCommandRunner::printing(RA_HASHER_HASH);

    $hash = raHasherWith($runner, $this->temporary)->hash(gameFile($this->games, 'Sonic.md'), 1);

    expect($hash)->toBe(md5('rom'))
        ->and($runner->runs)->toBe([]);
});

it('falls back to rahasher when the native hasher cannot hash the file', function (): void {
    $runner = FakeCommandRunner::printing(RA_HASHER_HASH);
    $playlist = gameFile($this->games, 'Sonic CD.m3u');

    $hash = raHasherWith($runner, $this->temporary)->hash($playlist, 1);

    expect($hash)->toBe(RA_HASHER_HASH)
        ->and($runner->commands())->toBe(['RAHasher.exe 1 "' . $playlist->getPathname() . '"']);
});

it('reads the hash from the last line rahasher prints, in lower case', function (): void {
    $runner = new FakeCommandRunner(static fn (): CommandResult => new CommandResult("Loading...\n1BC674BE034E43C96B86487AC69D9293\n"));

    expect(raHasherWith($runner, $this->temporary)->hash(gameFile($this->games, 'Game.bin'), 999))->toBe(RA_HASHER_HASH);
});

it('returns an empty hash when rahasher does not print one', function (): void {
    $runner = new FakeCommandRunner(static fn (): CommandResult => new CommandResult('', 'Unsupported file', 1));

    expect(raHasherWith($runner, $this->temporary)->hash(gameFile($this->games, 'Game.bin'), 999))->toBe('');
});

it('hashes files with non ascii paths through a temporary copy', function (): void {
    $copies = [];
    $runner = new FakeCommandRunner(static function (string $command) use (&$copies): CommandResult {
        preg_match('/"([^"]+)"/', $command, $path);
        $copies[] = file_get_contents($path[1]);

        return new CommandResult(RA_HASHER_HASH);
    });
    $file = gameFile($this->games, 'Ёжик.bin', 'hedgehog');

    $hash = raHasherWith($runner, $this->temporary)->hash($file, 999);

    expect($hash)->toBe(RA_HASHER_HASH)
        ->and($runner->commands()[0])->toStartWith('RAHasher.exe 999 "' . $this->temporary . DIRECTORY_SEPARATOR . 'ra-hasher-')
        ->and($copies)->toBe(['hedgehog'])
        ->and(temporaryFiles($this->temporary))->toBe([])
        ->and(file_exists($file->getPathname()))->toBeTrue();
});

it('removes the temporary copies when rahasher cannot be run', function (): void {
    $runner = new FakeCommandRunner(static fn (): CommandResult => throw new RuntimeException('Could not run'));

    expect(fn (): string => raHasherWith($runner, $this->temporary)->hash(gameFile($this->games, 'Pokémon.bin'), 999))
        ->toThrow(RuntimeException::class, 'Could not run')
        ->and(temporaryFiles($this->temporary))->toBe([]);
});

it('hands arcade files with non ascii names to rahasher as they are, because their hash is the name', function (): void {
    $runner = FakeCommandRunner::printing(RA_HASHER_HASH);
    $file = new SplFileInfo($this->games . DIRECTORY_SEPARATOR . 'Pokémon.zip');

    raHasherWith($runner, $this->temporary)->raHasher([$file], 27);

    expect($runner->commands())->toBe(['RAHasher.exe 27 "' . $file->getPathname() . '"']);
});

it('converts the gamecube games it cannot read to iso before hashing them', function (): void {
    $runner = dolphinRunner();
    $file = gameFile($this->games, 'Zelda.rvz');

    $hash = raHasherWith($runner, $this->temporary)->hash($file, RaHasher::GAME_CUBE);

    $user = $this->temporary . DIRECTORY_SEPARATOR . 'ra-hasher-dolphin';
    [$convert, $raHasher] = $runner->commands();
    preg_match('/--output="([^"]+)"/', $convert, $iso);
    expect($hash)->toBe(RA_HASHER_HASH)
        ->and($convert)->toStartWith('DolphinTool.exe convert --user="' . $user . '" --format=iso --input="' . $file->getPathname() . '" --output="')
        ->and($raHasher)->toBe('RAHasher.exe 16 "' . $iso[1] . '"')
        ->and(dirname($iso[1]))->toBe($this->temporary)
        ->and(file_exists($iso[1]))->toBeFalse()
        ->and(file_get_contents($user . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . 'Dolphin.ini'))->toBe("[Interface]\nUsePanicHandlers = False\n");
});

it('reuses the dolphin user folder for the next conversions', function (): void {
    $raHasher = raHasherWith(dolphinRunner(), $this->temporary);

    $first = $raHasher->hash(gameFile($this->games, 'Zelda.rvz'), RaHasher::GAME_CUBE);
    $second = $raHasher->hash(gameFile($this->games, 'Mario.rvz'), RaHasher::GAME_CUBE);

    expect([$first, $second])->toBe([RA_HASHER_HASH, RA_HASHER_HASH]);
});

it('returns an empty hash when dolphin cannot convert the game', function (): void {
    $runner = dolphinRunner(converts: false);

    $hash = raHasherWith($runner, $this->temporary)->hash(gameFile($this->games, 'Zelda.rvz'), RaHasher::GAME_CUBE);

    expect($hash)->toBe('')
        ->and($runner->commands())->toHaveCount(1);
});

it('converts the wii containers rahasher cannot read to iso before hashing them', function (string $extension): void {
    $runner = dolphinRunner();

    $hash = raHasherWith($runner, $this->temporary)->hash(gameFile($this->games, 'Zelda.' . $extension), RaHasher::WII);

    expect($hash)->toBe(RA_HASHER_HASH)
        ->and($runner->commands()[0])->toStartWith('DolphinTool.exe convert');
})->with(['wbfs', 'WBFS', 'rvz', 'wia', 'gcz', 'ciso']);

it('hands the wii iso and wad files it cannot read straight to rahasher', function (string $name): void {
    $runner = FakeCommandRunner::printing(RA_HASHER_HASH);
    $file = gameFile($this->games, $name);

    $hash = raHasherWith($runner, $this->temporary)->hash($file, RaHasher::WII);

    expect($hash)->toBe(RA_HASHER_HASH)
        ->and($runner->commands())->toBe(['RAHasher.exe 19 "' . $file->getPathname() . '"']);
})->with(['Zelda.iso', 'Zelda.wad']);

it('hashes many files at once, running rahasher on the ones left at the same time', function (): void {
    $runner = new FakeCommandRunner(static fn (string $command): CommandResult => new CommandResult(md5($command)));
    $playlist = gameFile($this->games, 'Sonic CD.m3u');
    $rom = gameFile($this->games, 'Sonic.md');
    $other = gameFile($this->games, 'Knuckles CD.m3u');
    $hashed = [];

    $hashes = raHasherWith($runner, $this->temporary)->hashMany([$playlist, $rom, $other], 1, static function (SplFileInfo $file, string $hash) use (&$hashed): void {
        $hashed[$file->getFilename()] = $hash;
    });

    $playlistHash = md5('RAHasher.exe 1 "' . $playlist->getPathname() . '"');
    $otherHash = md5('RAHasher.exe 1 "' . $other->getPathname() . '"');
    expect($hashes)->toBe([$playlistHash, md5('rom'), $otherHash])
        ->and($hashed)->toBe(['Sonic.md' => md5('rom'), 'Sonic CD.m3u' => $playlistHash, 'Knuckles CD.m3u' => $otherHash])
        ->and($runner->runs)->toHaveCount(1);
});

it('runs nothing when rahasher has no files to hash', function (): void {
    $runner = FakeCommandRunner::printing(RA_HASHER_HASH);

    expect(raHasherWith($runner, $this->temporary)->raHasher([], 1))->toBe([])
        ->and($runner->runs)->toBe([]);
});

it('uses the system temporary folder by default', function (): void {
    $runner = FakeCommandRunner::printing(RA_HASHER_HASH);

    new RaHasher('RAHasher.exe', 'DolphinTool.exe', $runner)->hash(gameFile($this->games, 'Pokémon.bin'), 999);

    expect($runner->commands()[0])->toStartWith('RAHasher.exe 999 "' . sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ra-hasher-');
});

it('tells the second and later parts of a split wbfs image', function (string $name, bool $isSplitPart): void {
    expect(RaHasher::isWbfsSplitPart(new SplFileInfo($name)))->toBe($isSplitPart);
})->with([
    ['Game.wbf1', true],
    ['Game.WBF9', true],
    ['Game.wbfs', false],
    ['Game.wbf0', false],
    ['Game.iso', false],
]);
