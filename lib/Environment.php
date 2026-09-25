<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup;

final class Environment
{
    /** @param array<string,string> $vars */
    public function __construct(private readonly array $vars) {}

    public static function fromGlobals(): self
    {
        $vars = [];
        foreach (getenv() as $k => $v) {
            $vars[(string) $k] = (string) $v;
        }
        return new self($vars);
    }

    public function get(string $name): ?string
    {
        $value = $this->vars[$name] ?? null;
        return ($value === null || $value === '') ? null : $value;
    }

    /** @param array<string,string> $overrides */
    public function with(array $overrides): self
    {
        return new self(array_merge($this->vars, $overrides));
    }

    /** @return array<string,string> */
    public function all(): array
    {
        return $this->vars;
    }
}
