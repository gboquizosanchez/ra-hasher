<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Runner;

final readonly class CommandResult
{
    public function __construct(
        public string $output,
        public string $errorOutput = '',
        public int $exitCode = 0,
    ) {}
}
