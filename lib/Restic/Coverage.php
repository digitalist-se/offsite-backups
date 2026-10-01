<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Restic;

/** How many snapshots of each class a repository holds against the configured retention. */
final class Coverage
{
    /**
     * @param array<string,int> $counts class => snapshots present
     * @param array<string,int> $keep   class => snapshots the retention keeps
     */
    public function __construct(
        public readonly array $counts,
        public readonly array $keep,
        public readonly ?\DateTimeImmutable $oldest,
        public readonly ?\DateTimeImmutable $newest,
    ) {}

    /**
     * @param list<Snapshot> $snapshots
     * @param array<string,int> $keep
     */
    public static function of(array $snapshots, array $keep): self
    {
        $counts = array_fill_keys(array_keys($keep), 0);
        $oldest = $newest = null;
        foreach ($snapshots as $snapshot) {
            foreach ($snapshot->tags as $tag) {
                if (array_key_exists($tag, $counts)) {
                    $counts[$tag]++;
                }
            }
            $oldest = $oldest === null || $snapshot->time < $oldest ? $snapshot->time : $oldest;
            $newest = $newest === null || $snapshot->time > $newest ? $snapshot->time : $newest;
        }
        return new self($counts, $keep, $oldest, $newest);
    }

    public function describe(): string
    {
        $parts = [];
        foreach ($this->keep as $class => $keep) {
            $parts[] = sprintf('%s %d of %d', $class, $this->counts[$class] ?? 0, $keep);
        }
        return implode(', ', $parts);
    }
}
