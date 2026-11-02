<?php

declare(strict_types=1);

use Boquizo\RaHasher\Runner\CommandResult;
use Boquizo\RaHasher\Runner\CommandRunner;

/** Records the commands instead of running them and answers with the given closure. */
final class FakeCommandRunner implements CommandRunner
{
    /** @var list<list<string>> the commands of each run */
    public array $runs = [];

    /** @param Closure(string): CommandResult $respond */
    public function __construct(
        private readonly Closure $respond,
    ) {}

    /** Answers every RAHasher command with the hash and does nothing else. */
    public static function printing(string $hash): self
    {
        return new self(static fn (): CommandResult => new CommandResult($hash));
    }

    #[Override]
    public function run(array $commands): array
    {
        $this->runs[] = $commands;

        return array_map($this->respond, $commands);
    }

    /** @return list<string> */
    public function commands(): array
    {
        return array_merge(...$this->runs);
    }
}
