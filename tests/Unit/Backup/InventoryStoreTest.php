<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tests\Unit\Backup;

use Digitalist\OffsiteBackup\Backup\Inventory;
use Digitalist\OffsiteBackup\Backup\InventoryStore;
use Digitalist\OffsiteBackup\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class InventoryStoreTest extends TestCase
{
    public function testWritesOneFilePerStoreAndReadsItBack(): void
    {
        $dir = TempDir::create() . '/backups';
        $store = new InventoryStore($dir);
        self::assertNull($store->read('db'), 'nothing recorded yet');

        $inventory = new Inventory(['daily' => 7, 'biweekly' => 1, 'monthly' => 12], 20, new \DateTimeImmutable('2025-10-01 01:00:00'), new \DateTimeImmutable('2026-10-08 01:05:00'), 'db:backup');
        $store->write('db', $inventory);
        self::assertSame("$dir/inventory-db.json", $store->path('db'));
        self::assertFileExists("$dir/inventory-db.json");
        self::assertFileDoesNotExist("$dir/inventory-db.json.tmp");
        self::assertSame('0600', substr(sprintf('%o', fileperms("$dir/inventory-db.json")), -4));

        $read = $store->read('db');
        self::assertNotNull($read);
        self::assertSame($inventory->counts, $read->counts);
        self::assertSame('db:backup', $read->recordedBy);
        self::assertNull($store->read('files'));
    }

    public function testACorruptFileReadsAsNoBaseline(): void
    {
        $dir = TempDir::create();
        file_put_contents("$dir/inventory-files.json", '{not json');
        self::assertNull((new InventoryStore($dir))->read('files'));
        file_put_contents("$dir/inventory-files.json", '[1, 2, 3]');
        self::assertNull((new InventoryStore($dir))->read('files'));
    }
    public function testABaselineRecordedForAnotherHostReadsAsNoBaseline(): void
    {
        $dir = TempDir::create();
        $store = new InventoryStore($dir);
        $store->write('db', new Inventory(['daily' => 7, 'biweekly' => 1, 'monthly' => 12], 20, null, new \DateTimeImmutable('2026-10-08 01:05:00'), 'db:backup', 'proj-main'));
        self::assertNotNull($store->read('db', 'proj-main'));
        self::assertNotNull($store->read('db'), 'no host given, no filter');
        self::assertNull($store->read('db', 'proj-stage'), 'renamed environment: the old baseline does not apply');

        file_put_contents("$dir/inventory-files.json", (string) json_encode(['counts' => ['daily' => 3], 'recorded' => '2026-10-08T01:05:00+00:00']));
        self::assertNotNull($store->read('files', 'proj-main'), 'a baseline without a host, from before it was recorded, applies to any host');
    }
}
