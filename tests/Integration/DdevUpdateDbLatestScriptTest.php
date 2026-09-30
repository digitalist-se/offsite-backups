<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tests\Integration;

use Digitalist\OffsiteBackup\Process\ProcessResult;
use Digitalist\OffsiteBackup\Tests\Support\IntegrationTestCase;

/**
 * The host-side ddev script, run directly (OB_EXEC empty) against the real
 * binary, restic and S3 container, with fake-bw.sh in place of Bitwarden.
 */
final class DdevUpdateDbLatestScriptTest extends IntegrationTestCase
{
    private string $script;
    private string $binDir;

    protected function setUp(): void
    {
        parent::setUp();
        $package = dirname(__DIR__, 2);
        $this->script = $package . '/ddev/update-db-latest.sh';
        $restic = $this->restic();
        $db = $this->repositoryUrl('database');
        $restic->init($db);
        foreach (['2026-09-20' => 'older dump', '2026-09-21' => 'newer dump'] as $day => $content) {
            $restic->backupFromShell($db, 'printf "%s" "$C"', ['C' => "-- $content\n-- Dump completed on $day\n"], "$day-proj-main.sql", 'daily', 'proj-main', "$day 01:00:00");
        }
        $s3 = $this->s3Env();
        file_put_contents($this->projectRoot . '/offsite-backup.yml', "project: proj\ns3: {host: {$s3['host']}, bucket: {$s3['bucket']}}\nlocal_dir: {$this->projectRoot}/backups\nrestic: {bin: {$this->resticBin()}}\n");
        $this->binDir = $this->projectRoot . '/fakebin';
        mkdir($this->binDir);
        symlink($package . '/tests/Support/fake-bw.sh', $this->binDir . '/bw');
    }

    /**
     * @param list<string> $args
     * @param array<string,string> $env
     */
    private function runScript(array $args, ?string $stdin = null, array $env = []): ProcessResult
    {
        $fields = [['name' => 'AWS_ACCESS_KEY_ID', 'value' => $this->s3Env()['key']], ['name' => 'AWS_SECRET_ACCESS_KEY', 'value' => $this->s3Env()['secret']], ['name' => 'RESTIC_PASSWORD', 'value' => 'test-password'], ['name' => 'unrelated', 'value' => 'x']];
        return $this->runner()->run(array_merge(['bash', $this->script, '--bw-item=[Site] Offsite backup', '--environment=main', '--root=' . $this->projectRoot, '--bin=' . dirname(__DIR__, 2) . '/bin/offsite-backup', '--restic-bin=' . $this->resticBin()], $args), $env + [
            'PATH' => $this->binDir . ':' . getenv('PATH'),
            'OB_EXEC' => '',
            'OFFSITE_BACKUP_CONFIG' => $this->projectRoot . '/offsite-backup.yml',
            'FAKE_BW_ITEM_NAME' => '[Site] Offsite backup',
            'FAKE_BW_ITEM_JSON' => (string) json_encode(['fields' => $fields]),
        ], $stdin, 300);
    }

    public function testLatestDownloadsRenamesAndLinks(): void
    {
        $result = $this->runScript(['--latest']);
        self::assertTrue($result->ok(), $result->tail(15));
        $file = $this->projectRoot . '/sql/20260921-prod.sql.gz';
        self::assertFileExists($file);
        self::assertSame("-- newer dump\n-- Dump completed on 2026-09-21\n", file_get_contents("compress.zlib://$file"));
        self::assertSame('20260921-prod.sql.gz', readlink($this->projectRoot . '/sql/db_latest.sql.gz'));
        self::assertStringContainsString('Dump completed on 2026-09-21', $result->stdout);
        self::assertFileDoesNotExist($this->projectRoot . '/sql/2026-09-21-proj-main.sql.gz', 'renamed, not copied');

        // Second run: cached, nothing downloaded again.
        $mtime = filemtime($file);
        sleep(1);
        $again = $this->runScript(['--latest']);
        self::assertTrue($again->ok(), $again->tail(10));
        self::assertStringContainsString('cached', $again->stdout);
        clearstatcache();
        self::assertSame($mtime, filemtime($file));
    }

    public function testListShowsNewestFirstWithoutDownloading(): void
    {
        $result = $this->runScript(['--list']);
        self::assertTrue($result->ok(), $result->tail(10));
        self::assertLessThan(strpos($result->stdout, '2026-09-20'), strpos($result->stdout, '2026-09-21'));
        self::assertStringContainsString('daily', $result->stdout);
        self::assertSame([], glob($this->projectRoot . '/sql/*') ?: []);
    }

    public function testDatePicksThatDumpAndUnknownDateFailsWithoutTouchingTheLink(): void
    {
        self::assertTrue($this->runScript(['--date=2026-09-20'])->ok());
        self::assertSame('20260920-prod.sql.gz', readlink($this->projectRoot . '/sql/db_latest.sql.gz'));

        $bad = $this->runScript(['--date=2026-01-01']);
        self::assertSame(1, $bad->exitCode);
        self::assertStringContainsString('No dump dated 2026-01-01', $bad->tail(10));
        self::assertStringContainsString('2026-09-21', $bad->tail(10), 'lists the available dates');
        self::assertSame('20260920-prod.sql.gz', readlink($this->projectRoot . '/sql/db_latest.sql.gz'));
    }

    public function testInteractivePickerRepromptsOnBadInputAndDefaultsToNewest(): void
    {
        $result = $this->runScript(['--interactive'], "abc\n99\n2\n");
        self::assertTrue($result->ok(), $result->tail(15));
        self::assertSame(2, substr_count($result->stdout, 'Please enter a number'));
        self::assertSame('20260920-prod.sql.gz', readlink($this->projectRoot . '/sql/db_latest.sql.gz'));

        $default = $this->runScript(['--interactive'], "\n");
        self::assertTrue($default->ok(), $default->tail(15));
        self::assertSame('20260921-prod.sql.gz', readlink($this->projectRoot . '/sql/db_latest.sql.gz'));

        $noTty = $this->runScript([]);
        self::assertTrue($noTty->ok(), $noTty->tail(15));
        self::assertStringContainsString('using the newest dump', $noTty->stdout);
    }

    public function testKeepLocalPrunesOlderFilesButNeverTheLinkedOne(): void
    {
        self::assertTrue($this->runScript(['--date=2026-09-20'])->ok());
        self::assertTrue($this->runScript(['--latest', '--keep-local=1'])->ok());
        self::assertFileExists($this->projectRoot . '/sql/20260921-prod.sql.gz');
        self::assertFileDoesNotExist($this->projectRoot . '/sql/20260920-prod.sql.gz');
    }

    public function testFailureReasonsFromTheBinaryReachStderr(): void
    {
        $fields = [['name' => 'AWS_ACCESS_KEY_ID', 'value' => $this->s3Env()['key']], ['name' => 'AWS_SECRET_ACCESS_KEY', 'value' => $this->s3Env()['secret']], ['name' => 'RESTIC_PASSWORD', 'value' => 'wrong-password']];
        $result = $this->runScript(['--latest'], null, ['FAKE_BW_ITEM_JSON' => (string) json_encode(['fields' => $fields])]);
        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString('Could not list the dumps', $result->stderr);
        self::assertStringContainsString('Cannot access repository', $result->stderr, "the binary's own error is not swallowed");
    }

    public function testQuotedExecBranchWorksLikeDdevExec(): void
    {
        // OB_EXEC non-empty takes the printf %q branch used with `ddev exec`; `bash -c` re-parses the one argument the same way.
        $result = $this->runScript(['--list', '--exec=bash -c']);
        self::assertTrue($result->ok(), $result->tail(10));
        self::assertStringContainsString('2026-09-21', $result->stdout);
    }

    public function testMissingBitwardenFieldAndLockedVaultAreReportedBeforeAnyDownload(): void
    {
        $noPassword = $this->runner()->run(['bash', $this->script, '--bw-item=[Site] Offsite backup', '--root=' . $this->projectRoot, '--latest'], [
            'PATH' => $this->binDir . ':' . getenv('PATH'), 'OB_EXEC' => '',
            'FAKE_BW_ITEM_NAME' => '[Site] Offsite backup',
            'FAKE_BW_ITEM_JSON' => (string) json_encode(['fields' => [['name' => 'AWS_ACCESS_KEY_ID', 'value' => 'k'], ['name' => 'AWS_SECRET_ACCESS_KEY', 'value' => 's']]]),
        ], null, 60);
        self::assertSame(1, $noPassword->exitCode);
        self::assertStringContainsString('missing the field(s): RESTIC_PASSWORD', $noPassword->stderr);

        $locked = $this->runScript(['--latest'], null, ['FAKE_BW_STATUS' => 'locked']);
        self::assertSame(1, $locked->exitCode);
        self::assertStringContainsString('not unlocked', $locked->stderr);
        self::assertSame([], glob($this->projectRoot . '/sql/*') ?: []);
    }
}
