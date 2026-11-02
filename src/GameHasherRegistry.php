<?php

declare(strict_types=1);

namespace Boquizo\RaHasher;

use Boquizo\RaHasher\Hashers\ArcadeHasher;
use Boquizo\RaHasher\Hashers\ArduboyHasher;
use Boquizo\RaHasher\Hashers\DreamcastHasher;
use Boquizo\RaHasher\Hashers\GameCubeHasher;
use Boquizo\RaHasher\Hashers\GuardedHasher;
use Boquizo\RaHasher\Hashers\HeaderSkippingHasher;
use Boquizo\RaHasher\Hashers\NeoGeoCdHasher;
use Boquizo\RaHasher\Hashers\Nintendo64Hasher;
use Boquizo\RaHasher\Hashers\NintendoDsHasher;
use Boquizo\RaHasher\Hashers\PcEngineCdHasher;
use Boquizo\RaHasher\Hashers\PcFxHasher;
use Boquizo\RaHasher\Hashers\PlayStation2Hasher;
use Boquizo\RaHasher\Hashers\PlayStationHasher;
use Boquizo\RaHasher\Hashers\PlayStationPortableHasher;
use Boquizo\RaHasher\Hashers\SegaCdHasher;
use Boquizo\RaHasher\Hashers\ThreeDoHasher;
use Boquizo\RaHasher\Hashers\WholeFileHasher;
use Boquizo\RaHasher\Hashers\WiiHasher;

/**
 * Systems (RetroAchievements console ids) hashed in PHP. Any other system is
 * still hashed by RAHasher.
 */
final class GameHasherRegistry
{
    /** Systems hashed as a whole file, including the disk-based ones. */
    private const array WHOLE_FILE_SYSTEMS = [
        1,  // Genesis/Mega Drive
        4,  // Game Boy
        5,  // Game Boy Advance
        6,  // Game Boy Color
        10, // 32X
        11, // Master System
        14, // Neo Geo Pocket
        15, // Game Gear
        17, // Atari Jaguar
        23, // Magnavox Odyssey 2
        24, // Pokemon Mini
        25, // Atari 2600
        28, // Virtual Boy
        29, // MSX
        33, // SG-1000
        37, // Amstrad CPC
        38, // Apple II
        44, // ColecoVision
        45, // Intellivision
        46, // Vectrex
        47, // PC-8000/8800
        53, // WonderSwan
        57, // Fairchild Channel F
        63, // Watara Supervision
        69, // Mega Duck
        72, // WASM-4
        73, // Arcadia 2001
        74, // Interton VC 4000
        75, // Elektor TV Games Computer
        80, // Uzebox
    ];

    private const int ARCADE = 27;

    public static function for(int $systemId): ?GameHasher
    {
        $hasher = self::hasherFor($systemId);

        return $hasher === null ? null : new GuardedHasher($hasher, $systemId !== self::ARCADE);
    }

    private static function hasherFor(int $systemId): ?GameHasher
    {
        return match (true) {
            in_array($systemId, self::WHOLE_FILE_SYSTEMS, true) => new WholeFileHasher(),
            $systemId === 2 => new Nintendo64Hasher(),
            $systemId === 3 => HeaderSkippingHasher::snes(),
            $systemId === 7, $systemId === 81 => HeaderSkippingHasher::nes(),
            $systemId === 8 => HeaderSkippingHasher::pcEngine(),
            $systemId === 13 => HeaderSkippingHasher::lynx(),
            $systemId === 9, $systemId === 39 => new SegaCdHasher(),
            $systemId === 43 => new ThreeDoHasher(),
            $systemId === 56 => new NeoGeoCdHasher(),
            $systemId === 76 => new PcEngineCdHasher(),
            $systemId === 49 => new PcFxHasher(),
            $systemId === 40 => new DreamcastHasher(),
            $systemId === 12 => new PlayStationHasher(),
            $systemId === 21 => new PlayStation2Hasher(),
            $systemId === 41 => new PlayStationPortableHasher(),
            $systemId === 18, $systemId === 78 => new NintendoDsHasher(),
            $systemId === 16 => new GameCubeHasher(),
            $systemId === 19 => new WiiHasher(),
            $systemId === self::ARCADE => new ArcadeHasher(),
            $systemId === 51 => HeaderSkippingHasher::atari7800(),
            $systemId === 71 => new ArduboyHasher(),
            default => null,
        };
    }
}
