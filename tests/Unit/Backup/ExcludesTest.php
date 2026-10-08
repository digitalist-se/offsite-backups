<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tests\Unit\Backup;

use Digitalist\OffsiteBackup\Backup\Excludes;
use Digitalist\OffsiteBackup\Config\Settings;
use PHPUnit\Framework\TestCase;

final class ExcludesTest extends TestCase
{
    public function testPlaceholderEntriesAreAnchoredToEveryPath(): void
    {
        $paths = ['/app/web/sites/default/files', '/app/private/'];
        self::assertSame(
            ['/app/web/sites/default/files/css', '/app/private/css', '/app/web/sites/default/files/config_*', '/app/private/config_*'],
            Excludes::expand($paths, ['{path}/css', '{path}/config_*']),
        );
    }

    public function testEntriesWithoutThePlaceholderPassThroughVerbatim(): void
    {
        self::assertSame(['**/css', '*.tmp', '/app/private/scratch'], Excludes::expand(['/app/web/sites/default/files'], ['**/css', '*.tmp', '/app/private/scratch']));
    }

    public function testDuplicatesAreDropped(): void
    {
        self::assertSame(['/a/css', '**/js'], Excludes::expand(['/a', '/a/'], ['{path}/css', '**/js', '**/js']));
    }

    public function testTheDrupalDefaultsAreAllAnchored(): void
    {
        foreach (Settings::DRUPAL_EXCLUDES as $pattern) {
            self::assertStringStartsWith('{path}/', $pattern);
        }
        $expanded = Excludes::expand(['/app/web/sites/default/files'], Settings::DRUPAL_EXCLUDES);
        self::assertContains('/app/web/sites/default/files/styles', $expanded);
        self::assertCount(count(Settings::DRUPAL_EXCLUDES), $expanded);
    }
}
