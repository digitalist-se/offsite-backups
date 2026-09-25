<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tests\Unit\Process;

use Digitalist\OffsiteBackup\Process\ProcessRunner;
use PHPUnit\Framework\TestCase;

final class ProcessRunnerTest extends TestCase
{
    public function testArrayCommandCapturesOutputAndExitCode(): void
    {
        $result = (new ProcessRunner())->run(['sh', '-c', 'echo out; echo err >&2; exit 3']);
        self::assertSame(3, $result->exitCode);
        self::assertSame("out\n", $result->stdout);
        self::assertSame("err\n", $result->stderr);
        self::assertFalse($result->ok());
        self::assertSame("out\nerr", $result->tail());
    }

    public function testShellCommandResolvesEnvPlaceholdersAndStreamsStdout(): void
    {
        $chunks = [];
        $result = (new ProcessRunner())->run('printf "%s" "$MSG"', ['MSG' => 'hello world'], null, 10, static function (string $chunk) use (&$chunks): void { $chunks[] = $chunk; });
        self::assertTrue($result->ok());
        self::assertSame('', $result->stdout, 'streamed output is not accumulated');
        self::assertSame('hello world', implode('', $chunks));
    }

    public function testInputIsPassedOnStdin(): void
    {
        $result = (new ProcessRunner())->run(['cat'], [], "line1\nline2\n");
        self::assertSame("line1\nline2\n", $result->stdout);
    }

    public function testTimeoutBecomesAFailedResult(): void
    {
        $result = (new ProcessRunner())->run(['sh', '-c', 'echo partial; sleep 5'], [], null, 1);
        self::assertSame(124, $result->exitCode);
        self::assertSame("partial\n", $result->stdout);
        self::assertStringContainsString('timed out after 1 seconds', $result->stderr);
    }

    public function testTailReturnsLastLines(): void
    {
        $result = (new ProcessRunner())->run(['sh', '-c', 'seq 1 30']);
        self::assertSame(implode("\n", range(11, 30)), $result->tail(20));
    }
}
