<?php

declare(strict_types=1);

namespace App\Tests\Module\Feed\Service;

use App\Module\Feed\Service\SitemapCacheRevisionProvider;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class SitemapCacheRevisionProviderTest extends TestCase
{
    public function testReturnsGenerationBoundDatabaseRevision(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with("SELECT generation || '-' || revision::text FROM sitemap_content_revision WHERE id = 1")
            ->willReturn(str_repeat('a', 32).'-42');

        self::assertSame(str_repeat('a', 32).'-42', new SitemapCacheRevisionProvider($connection)->current());
    }

    public function testAcceptsTheInitialZeroRevisionCreatedByTheMigration(): void
    {
        $revision = str_repeat('b', 32).'-0';
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchOne')->willReturn($revision);

        self::assertSame($revision, new SitemapCacheRevisionProvider($connection)->current());
    }

    public function testMissingOrMalformedRevisionFailsClosed(): void
    {
        foreach ([false, null, 'bad', str_repeat('a', 32).'-00', str_repeat('a', 32).'-01'] as $value) {
            $connection = $this->createMock(Connection::class);
            $connection->expects(self::once())->method('fetchOne')->willReturn($value);

            try {
                new SitemapCacheRevisionProvider($connection)->current();
                self::fail('A malformed sitemap revision must not select a cache entry.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Die Sitemap-Revision konnte nicht sicher ermittelt werden.', $exception->getMessage());
            }
        }
    }
}
