<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tests\Integration;

use Digitalist\OffsiteBackup\Tests\Support\IntegrationTestCase;

final class DdevUpdateFilesLatestScriptTest extends IntegrationTestCase
{
    public function testRestoresTheLatestSnapshotIntoTheTarget(): void
    {
        $package = dirname(__DIR__, 2);
        $s3 = $this->s3Env();
        $src = $this->projectRoot . '/web/sites/default/files';
        mkdir("$src/sub", 0700, true);
        file_put_contents("$src/a.txt", 'A');
        file_put_contents("$src/sub/b.txt", 'B');
        $this->restic()->init($this->repositoryUrl('public-files'));
        $this->restic()->backupPaths($this->repositoryUrl('public-files'), [$src], [], 'daily', 'proj-main');
        file_put_contents("$src/a.txt", 'changed locally');
        file_put_contents($this->projectRoot . '/offsite-backup.yml', "project: proj\ns3: {host: {$s3['host']}, bucket: {$s3['bucket']}}\nlocal_dir: {$this->projectRoot}/backups\nrestic: {bin: {$this->resticBin()}}\n");
        $binDir = $this->projectRoot . '/fakebin';
        mkdir($binDir);
        symlink("$package/tests/Support/fake-bw.sh", "$binDir/bw");

        $result = $this->runner()->run(['bash', "$package/ddev/update-files-latest.sh", '--bw-item=[Site] Offsite backup', '--environment=main', '--root=' . $this->projectRoot, '--bin=' . "$package/bin/offsite-backup", '--restic-bin=' . $this->resticBin(), '--target=web/sites/default/files'], [
            'PATH' => $binDir . ':' . getenv('PATH'), 'OB_EXEC' => '',
            'OFFSITE_BACKUP_CONFIG' => $this->projectRoot . '/offsite-backup.yml',
            'FAKE_BW_ITEM_NAME' => '[Site] Offsite backup',
            'FAKE_BW_ITEM_JSON' => (string) json_encode(['fields' => [['name' => 'AWS_ACCESS_KEY_ID', 'value' => $s3['key']], ['name' => 'AWS_SECRET_ACCESS_KEY', 'value' => $s3['secret']], ['name' => 'RESTIC_PASSWORD', 'value' => 'test-password']]]),
        ], null, 300);
        self::assertTrue($result->ok(), $result->tail(15));
        self::assertSame('A', file_get_contents("$src/a.txt"), 'restored over the local change');
        self::assertSame('B', file_get_contents("$src/sub/b.txt"));
        self::assertStringContainsString('Restored', $result->stdout);
    }
}
