<?php

declare(strict_types=1);

namespace App\Tests\Module\Automation\Application;

use App\Entity\ScheduledTask;
use App\Entity\ScheduledTaskType;
use App\Module\Automation\Application\SitemapRefreshTaskHandler;
use App\Module\Feed\Service\CanonicalPublicUrlGenerator;
use App\Module\Feed\Service\SitemapCacheRevisionProvider;
use App\Module\Feed\Service\SitemapDocumentBuilderInterface;
use App\Module\Feed\Service\SitemapDocumentService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class SitemapRefreshTaskHandlerTest extends TestCase
{
    public function testScheduledRefreshRebuildsBothPublicSitemapDocuments(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->willReturn(str_repeat('d', 32).'-3');
        $builder = new class implements SitemapDocumentBuilderInterface {
            public int $contentBuilds = 0;
            public int $videoBuilds = 0;

            public function contentSitemap(): string
            {
                ++$this->contentBuilds;

                return 'content-sitemap';
            }

            public function sitemapIndex(): string
            {
                return 'sitemap-index';
            }

            public function videoSitemap(): string
            {
                ++$this->videoBuilds;

                return 'video-sitemap';
            }
        };
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->expects(self::atLeastOnce())->method('generate')->willReturn('/');
        $sitemaps = new SitemapDocumentService(
            new ArrayAdapter(),
            $builder,
            new SitemapCacheRevisionProvider($connection),
            new CanonicalPublicUrlGenerator($router, 'https://arcanorum.example.test'),
        );
        $handler = new SitemapRefreshTaskHandler($sitemaps);
        $now = new \DateTimeImmutable('2026-10-29T12:00:00+00:00');
        $task = ScheduledTask::create(ScheduledTaskType::SitemapRefresh, 'Refresh sitemaps', 60, [], $now);

        $summary = $handler->execute($task, $now);

        self::assertSame('Reguläre Sitemap und Video-Sitemap wurden aktualisiert.', $summary);
        self::assertSame(1, $builder->contentBuilds);
        self::assertSame(1, $builder->videoBuilds);
    }
}
