<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Backup;

/** Advisory flock per command so two runs never overlap. */
final class RunLock
{
    /** @param resource $handle */
    private function __construct(private $handle, private readonly string $path) {}

    public static function acquire(string $dir, string $name): self
    {
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new LockException("Cannot create lock directory $dir");
        }
        $path = $dir . '/' . preg_replace('/[^a-z0-9]+/i', '-', $name) . '.lock';
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new LockException("Cannot open lock file $path");
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            $holder = trim((string) stream_get_contents($handle));
            fclose($handle);
            throw new LockException(sprintf("Another '%s' run is in progress (%s). Exiting without doing anything.", $name, $holder !== '' ? $holder : 'holder unknown'));
        }
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, sprintf('since %s pid %d', (new \DateTimeImmutable())->format(DATE_ATOM), getmypid()));
        fflush($handle);
        return new self($handle, $path);
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
