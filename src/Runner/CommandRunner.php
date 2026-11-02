<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Runner;

/**
 * Runs the RAHasher and DolphinTool commands, so an application can run them
 * its own way (a framework process facade that can be faked in tests, logs...).
 */
interface CommandRunner
{
    /**
     * Runs the commands at the same time and waits for all of them.
     *
     * @param  list<string>  $commands
     * @return list<CommandResult> the results in the order of the commands
     */
    public function run(array $commands): array;
}
