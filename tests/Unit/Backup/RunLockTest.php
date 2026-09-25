<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tests\Unit\Backup;

use Digitalist\OffsiteBackup\Backup\LockException;
use Digitalist\OffsiteBackup\Backup\RunLock;
use Digitalist\OffsiteBackup\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class RunLockTest extends TestCase
{
    public function testSecondAcquisitionFailsWhileHeldAndSucceedsAfterRelease(): void
    {
        $dir = TempDir::create();
        $first = RunLock::acquire($dir, 'db:backup');
        try {
            RunLock::acquire($dir, 'db:backup');
            self::fail('expected LockException');
        } catch (LockException $e) {
            self::assertStringContainsString('db:backup', $e->getMessage());
            self::assertMatchesRegularExpression('/since \d{4}-\d{2}-\d{2}T/', $e->getMessage());
        }
        // A different command is independent.
        RunLock::acquire($dir, 'files:backup')->release();
        $first->release();
        RunLock::acquire($dir, 'db:backup')->release();
        self::assertFileExists($dir . '/db-backup.lock');
    }
}
