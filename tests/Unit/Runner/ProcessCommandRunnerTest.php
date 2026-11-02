<?php

declare(strict_types=1);

use Boquizo\RaHasher\Runner\CommandResult;
use Boquizo\RaHasher\Runner\ProcessCommandRunner;

function phpCommand(string $code): string
{
    return escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code);
}

it('runs the commands and returns their output, error output and exit code in order', function (): void {
    $results = new ProcessCommandRunner()->run([
        phpCommand("echo 'first';"),
        phpCommand("fwrite(STDERR, 'broken'); exit(3);"),
    ]);

    expect($results)->toEqual([
        new CommandResult('first'),
        new CommandResult('', 'broken', 3),
    ]);
});

it('runs the commands at the same time', function (): void {
    $start = microtime(true);

    new ProcessCommandRunner()->run(array_fill(0, 3, phpCommand('usleep(500000);')));

    expect(microtime(true) - $start)->toBeLessThan(1.4);
});
