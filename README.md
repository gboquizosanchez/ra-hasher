<div align="center">

<img src="https://raw.githubusercontent.com/twitter/twemoji/master/assets/svg/1f3ae.svg" width="100" alt="RA Hasher">

# `gboquizosanchez/ra-hasher`

**RetroAchievements game hashes in pure PHP**

[![Latest Stable Version](https://img.shields.io/packagist/v/gboquizosanchez/ra-hasher.svg)](https://packagist.org/packages/gboquizosanchez/ra-hasher)
[![Total Downloads](https://img.shields.io/packagist/dt/gboquizosanchez/ra-hasher.svg)](https://packagist.org/packages/gboquizosanchez/ra-hasher)
[![PHP](https://img.shields.io/badge/PHP-%5E8.4-777BB4?logo=php&logoColor=white)](https://packagist.org/packages/gboquizosanchez/ra-hasher)
[![License: MIT](https://img.shields.io/badge/License-MIT-22C55E.svg)](LICENSE.md)
[![Tests](https://img.shields.io/badge/Tests-Pest%20v5-9C27B0)](https://pestphp.com/)

---

*Identify your games the way [RetroAchievements](https://retroachievements.org) does, without shelling out to RAHasher for every file.*

</div>

---

## Overview

RetroAchievements identifies a game by a hash of its file, calculated differently for each system: the whole file, the file without its header, the boot executable of a disc… ([game identification](https://docs.retroachievements.org/developer-docs/game-identification.html)). The reference implementation is [rcheevos](https://github.com/RetroAchievements/rcheevos), used by RAHasher.

This package reimplements those rules in PHP, including readers for the compressed formats a collection usually has:

- **CHD** (v5, zlib, LZMA and FLAC hunks) for CD based systems.
- **RVZ** (zstd or uncompressed) for GameCube.
- **WBFS**, split `.wbf1`…`.wbf9` parts included, for Wii.

It has been checked against RAHasher on a real collection of almost 8,000 files.

When a file cannot be hashed natively, the package can still fall back to RAHasher and DolphinTool, which you provide.

---

## Requirements

| Package | PHP  | Extensions                    |
|---------|------|-------------------------------|
| 0.x     | ^8.4 | `hash`, `mbstring`, `zlib`    |

---

## 📦 Installation

```bash
composer require gboquizosanchez/ra-hasher
```

---

## 🔧 Usage

### Native hashes only

```php
use Boquizo\RaHasher\GameHasherRegistry;

$hasher = GameHasherRegistry::for(21); // RetroAchievements console id (21 = PlayStation 2)

$hash = $hasher?->hash(new SplFileInfo('Devil May Cry 2 (Disc 1).chd'));
```

`GameHasherRegistry::for()` returns `null` for a system without a native hasher, and `hash()` returns `null` for a file it cannot hash like RAHasher would (a `.zip` outside arcade, an `.m3u` playlist, an unreadable file…).

### With the RAHasher and DolphinTool fallback

```php
use Boquizo\RaHasher\RaHasher;

$raHasher = new RaHasher(
    raHasherPath: 'C:\Tools\RAHasher.exe',
    dolphinToolPath: 'C:\Tools\DolphinTool.exe',
);

$hash = $raHasher->hash(new SplFileInfo('Sonic CD.m3u'), 1); // '' when it cannot be hashed
```

- GameCube games and the Wii containers RAHasher cannot read (`.wbfs`, `.rvz`, `.wia`, `.gcz`, `.ciso`) are converted to ISO with DolphinTool first, in the temporary folder, with Dolphin's panic dialogs turned off.
- Files whose path has non ASCII characters, which RAHasher cannot open, are hashed through a temporary copy.
- `hashMany()` hashes several files and runs RAHasher on the ones left at the same time; `raHasher()` skips the native hashers, which is handy to compare both.
- Pass `phpBinary` (for instance `PHP_BINARY`) and `hashMany()` hashes the files natively at the same time too, each in its own PHP process through `bin/ra-hasher`. A big compressed disc is several times faster this way.

RAHasher and DolphinTool are not bundled: get [RAHasher](https://github.com/RetroAchievements/RALibretro/releases) and [DolphinTool](https://dolphin-emu.org/download/) from their projects.

### Running the commands your own way

Commands run with `proc_open` by default. Implement `CommandRunner` to run them through your framework instead, for instance to fake them in tests or to log them:

```php
use Boquizo\RaHasher\Runner\CommandResult;
use Boquizo\RaHasher\Runner\CommandRunner;

final class MyCommandRunner implements CommandRunner
{
    public function run(array $commands): array
    {
        // Run the commands at the same time and return a CommandResult for each, in order.
    }
}

$raHasher = new RaHasher($raHasherPath, $dolphinToolPath, new MyCommandRunner());
```

---

## 🕹️ Supported systems

| Method | Systems |
|--------|---------|
| Whole file | Genesis/Mega Drive, Game Boy, Game Boy Color, Game Boy Advance, 32X, Master System, Neo Geo Pocket, Game Gear, Atari Jaguar, Magnavox Odyssey 2, Pokémon Mini, Atari 2600, Virtual Boy, MSX, SG-1000, Amstrad CPC, Apple II, ColecoVision, Intellivision, Vectrex, PC-8000/8800, WonderSwan, Fairchild Channel F, Watara Supervision, Mega Duck, WASM-4, Arcadia 2001, Interton VC 4000, Elektor TV Games Computer, Uzebox |
| Without header | NES, Famicom Disk System, SNES, PC Engine/SuperGrafx, Atari Lynx, Atari 7800 |
| Byte order normalised | Nintendo 64 |
| Cartridge layout | Nintendo DS, Nintendo DSi |
| Text with normalised line endings | Arduboy (Intel HEX) |
| File name | Arcade |
| Disc (ISO, BIN/CUE, CHD) | PlayStation, PlayStation 2, PSP, Sega CD, Sega Saturn, Dreamcast, Neo Geo CD, 3DO, PC Engine CD, PC-FX |
| Nintendo disc | GameCube (ISO, RVZ), Wii (ISO, WBFS, WAD) |

Every other system, and the cases above the native hashers leave aside (zstd compressed CHDs, `.m3u` playlists…), goes to RAHasher when you use `RaHasher`.

---

## 🧪 Testing

```bash
composer test
composer test:coverage
```

---

## 📄 License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
