<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Report;

final class RunReport
{
    public const SUCCESS = 'success';
    public const FAILURE = 'failure';
    public const SKIPPED = 'skipped';

    public string $outcome = 'running';
    public ?string $error = null;
    public ?\DateTimeImmutable $finishedAt = null;
    /** @var array<string,mixed> */
    public array $details = [];
    /** @var list<array{level: string, message: string, time: string}> */
    private array $messages = [];
    public readonly \DateTimeImmutable $startedAt;

    public function __construct(
        public readonly string $command,
        public readonly string $project,
        public readonly string $environment,
        ?\DateTimeImmutable $startedAt = null,
    ) {
        $this->startedAt = $startedAt ?? new \DateTimeImmutable();
    }

    public function addMessage(string $level, string $message): void
    {
        $this->messages[] = ['level' => $level, 'message' => $message, 'time' => (new \DateTimeImmutable())->format(DATE_ATOM)];
    }

    /** @return list<array{level: string, message: string, time: string}> */
    public function messages(): array
    {
        return $this->messages;
    }

    /** @return list<array{level: string, message: string, time: string}> */
    public function messagesSince(int $from): array
    {
        return array_values(array_slice($this->messages, $from));
    }

    public function count(): int
    {
        return count($this->messages);
    }

    public function succeed(): void
    {
        $this->outcome = self::SUCCESS;
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function fail(string $error): void
    {
        $this->outcome = self::FAILURE;
        $this->error = $error;
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function skip(string $reason): void
    {
        $this->outcome = self::SKIPPED;
        $this->error = $reason;
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function durationSeconds(): ?int
    {
        return $this->finishedAt === null ? null : $this->finishedAt->getTimestamp() - $this->startedAt->getTimestamp();
    }

    /**
     * The payload stored in Drupal State; messages are excluded on purpose.
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'command' => $this->command,
            'project' => $this->project,
            'environment' => $this->environment,
            'started' => $this->startedAt->format(DATE_ATOM),
            'finished' => $this->finishedAt?->format(DATE_ATOM),
            'duration_seconds' => $this->durationSeconds(),
            'outcome' => $this->outcome,
            'details' => $this->details,
            'error' => $this->error,
        ];
    }
}
