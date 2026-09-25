<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup;

final class Environment
{
    /** @param array<string,string> $vars */
    public function __construct(private readonly array $vars) {}

    public static function fromGlobals(): self
    {
        return new self(getenv());
    }

    public function get(string $name): ?string
    {
        $value = $this->vars[$name] ?? null;
        return ($value === null || $value === '') ? null : $value;
    }
}
