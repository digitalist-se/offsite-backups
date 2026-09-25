<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tests\Unit\Backup;

use Digitalist\OffsiteBackup\Backup\DumpChecker;
use Digitalist\OffsiteBackup\Backup\DumpCheckException;
use Digitalist\OffsiteBackup\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class DumpCheckerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = TempDir::create();
    }

    private function gz(string $name, string $content): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, gzencode($content, 6));
        return $path;
    }

    public static function completeDump(int $tables = 2, int $padding = 0): string
    {
        $sql = "-- MariaDB dump\n";
        for ($i = 1; $i <= $tables; $i++) {
            $sql .= "DROP TABLE IF EXISTS `t$i`;\nCREATE TABLE `t$i` (id int);\nINSERT INTO `t$i` VALUES (1);\n";
        }
        $sql .= str_repeat("-- pad\n", $padding);
        return $sql . "-- Dump completed on 2026-09-25  1:00:12\n";
    }

    public function testCompleteDumpPasses(): void
    {
        $result = (new DumpChecker())->check($this->gz('ok.sql.gz', self::completeDump(3, 20000)), 10);
        self::assertSame(3, $result->createTableCount);
        self::assertSame(strlen(self::completeDump(3, 20000)), $result->uncompressedBytes);
        self::assertGreaterThan(10, $result->compressedBytes);
    }

    public function testTruncatedDumpIsRejected(): void
    {
        $full = self::completeDump(2, 5000);
        $path = $this->gz('trunc.sql.gz', substr($full, 0, (int) (strlen($full) * 0.9)));
        $this->expectException(DumpCheckException::class);
        $this->expectExceptionMessage('Dump completed on');
        (new DumpChecker())->check($path, 10);
    }

    public function testDumpWithoutTablesIsRejected(): void
    {
        $path = $this->gz('empty.sql.gz', "-- nothing\n-- Dump completed on 2026-09-25  1:00:12\n");
        $this->expectException(DumpCheckException::class);
        $this->expectExceptionMessage('CREATE TABLE');
        (new DumpChecker())->check($path, 1);
    }

    public function testTooSmallIsRejected(): void
    {
        $path = $this->gz('small.sql.gz', self::completeDump());
        $this->expectException(DumpCheckException::class);
        $this->expectExceptionMessage('smaller than');
        (new DumpChecker())->check($path, 1024 * 1024);
    }

    public function testCorruptGzipIsRejected(): void
    {
        $path = $this->dir . '/corrupt.sql.gz';
        file_put_contents($path, substr(gzencode(self::completeDump(), 6), 0, 40) . 'garbage');
        $this->expectException(DumpCheckException::class);
        $this->expectExceptionMessage('gzip');
        (new DumpChecker())->check($path, 1);
    }

    public function testTruncatedGzipStreamIsRejectedAsGzipError(): void
    {
        $path = $this->dir . '/cut.sql.gz';
        $full = gzencode(self::completeDump(2, 5000), 6);
        file_put_contents($path, substr($full, 0, strlen($full) - 20));
        $this->expectException(DumpCheckException::class);
        $this->expectExceptionMessage('truncated archive');
        (new DumpChecker())->check($path, 1);
    }

    public function testCreateTableSplitAcrossReadChunksIsStillFound(): void
    {
        // 1 MiB of padding puts the only CREATE TABLE right on the chunk boundary.
        $pad = str_repeat('-', (1 << 20) - 6) . "\n";
        $sql = $pad . "CREATE TABLE `t` (id int);\n-- Dump completed on 2026-09-25  1:00:12\n";
        $result = (new DumpChecker())->check($this->gz('split.sql.gz', $sql), 1);
        self::assertSame(1, $result->createTableCount);
    }
}
