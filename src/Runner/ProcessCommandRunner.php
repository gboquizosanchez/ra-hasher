<?php

declare(strict_types=1);

namespace Boquizo\RaHasher\Runner;

use Override;
use RuntimeException;

/**
 * Runs the commands with proc_open. Their output goes to temporary files
 * instead of pipes, so a command that prints a lot never blocks the others.
 */
final readonly class ProcessCommandRunner implements CommandRunner
{
    #[Override]
    public function run(array $commands): array
    {
        $running = array_map(self::start(...), $commands);

        return array_map(self::finish(...), $running);
    }

    /** @return array{resource, resource, resource} */
    private static function start(string $command): array
    {
        $output = tmpfile() ?: throw new RuntimeException('Could not create a temporary file for ' . $command);
        $errorOutput = tmpfile() ?: throw new RuntimeException('Could not create a temporary file for ' . $command);
        $process = proc_open($command, [1 => $output, 2 => $errorOutput], $pipes) ?: throw new RuntimeException('Could not run ' . $command);

        return [$process, $output, $errorOutput];
    }

    /** @param array{resource, resource, resource} $running */
    private static function finish(array $running): CommandResult
    {
        [$process, $output, $errorOutput] = $running;
        $exitCode = proc_close($process);

        return new CommandResult(self::read($output), self::read($errorOutput), $exitCode);
    }

    /** @param resource $stream */
    private static function read($stream): string
    {
        rewind($stream);
        $contents = (string) stream_get_contents($stream);
        fclose($stream);

        return $contents;
    }
}
