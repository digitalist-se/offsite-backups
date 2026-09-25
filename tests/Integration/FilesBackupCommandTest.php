<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tests\Integration;

use Digitalist\OffsiteBackup\Application;
use Digitalist\OffsiteBackup\Tests\Support\IntegrationTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class FilesBackupCommandTest extends IntegrationTestCase
{
    public function testBacksUpConfiguredPathsWithExcludesAndVerifies(): void
    {
        mkdir($this->projectRoot . '/web/sites/default/files/css', 0700, true);
        mkdir($this->projectRoot . '/private', 0700, true);
        file_put_contents($this->projectRoot . '/web/sites/default/files/keep.jpg', 'jpg');
        file_put_contents($this->projectRoot . '/web/sites/default/files/css/agg.css', 'css');
        file_put_contents($this->projectRoot . '/private/secret.pdf', 'pdf');
        $this->restic()->init($this->repositoryUrl('public-files'));

        $app = new Application($this->projectRoot, $this->environment(['OFFSITE_BACKUP_FILES_PATHS' => 'web/sites/default/files,private']));
        $tester = new CommandTester($app->find('files:backup'));
        $code = $tester->execute([]);
        self::assertSame(0, $code, $tester->getDisplay());
        self::assertMatchesRegularExpression('/Files backup completed successfully \(snapshot [0-9a-f]{8}\)/', $tester->getDisplay());

        $snapshot = $this->restic()->snapshots($this->repositoryUrl('public-files'), ['host' => 'proj-main'])[0];
        // restic records paths sorted, whatever the configured order.
        self::assertEqualsCanonicalizing([$this->projectRoot . '/web/sites/default/files', $this->projectRoot . '/private'], $snapshot->paths);
        $restore = $this->projectRoot . '/restore';
        $this->restic()->restore($this->repositoryUrl('public-files'), $snapshot->id, $restore, null);
        self::assertFileExists($restore . $this->projectRoot . '/web/sites/default/files/keep.jpg');
        self::assertFileExists($restore . $this->projectRoot . '/private/secret.pdf');
        self::assertFileDoesNotExist($restore . $this->projectRoot . '/web/sites/default/files/css/agg.css');
    }

    public function testMissingPathFails(): void
    {
        $this->restic()->init($this->repositoryUrl('public-files'));
        $app = new Application($this->projectRoot, $this->environment(['OFFSITE_BACKUP_FILES_PATHS' => 'does/not/exist']));
        $tester = new CommandTester($app->find('files:backup'));
        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('does/not/exist', $tester->getDisplay());
        self::assertSame([], $this->restic()->snapshots($this->repositoryUrl('public-files')));
    }
}
