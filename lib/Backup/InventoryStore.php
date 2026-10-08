<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Backup;

/** Keeps the last recorded inventory per store as a JSON file in the local directory. */
final class InventoryStore
{
    public function __construct(private readonly string $dir) {}

    public function path(string $store): string
    {
        return $this->dir . '/inventory-' . (string) preg_replace('/[^a-z0-9]+/i', '-', $store) . '.json';
    }

    /**
     * The recorded inventory, or null when there is none, it is unreadable, or
     * it was recorded for another restic host (after renaming the environment).
     */
    public function read(string $store, ?string $host = null): ?Inventory
    {
        $path = $this->path($store);
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        $inventory = is_array($data) ? Inventory::fromArray($data) : null;
        if ($inventory !== null && $host !== null && $inventory->host !== '' && $inventory->host !== $host) {
            return null;
        }
        return $inventory;
    }

    public function write(string $store, Inventory $inventory): void
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("Cannot create {$this->dir}");
        }
        $path = $this->path($store);
        $tmp = $path . '.tmp';
        $json = json_encode($inventory->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($tmp, $json . "\n") === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot write $path");
        }
        @chmod($path, 0600);
    }
}
