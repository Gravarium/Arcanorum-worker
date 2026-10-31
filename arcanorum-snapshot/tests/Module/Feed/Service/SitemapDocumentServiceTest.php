<?php

declare(strict_types=1);

namespace App\Tests\Module\Feed\Service;

use App\Module\Feed\Service\CanonicalPublicUrlGenerator;
use App\Module\Feed\Service\SitemapCacheRevisionProvider;
use App\Module\Feed\Service\SitemapDocumentBuilderInterface;
use App\Module\Feed\Service\SitemapDocumentService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class SitemapDocumentServiceTest extends TestCase
{
    public function testContentSitemapIsCachedOnlyForItsDatabaseRevision(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(3))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(
                str_repeat('a', 32).'-1',
                str_repeat('a', 32).'-1',
                str_repeat('b', 32).'-1',
            );
        $builder = new class implements SitemapDocumentBuilderInterface {
            public int $contentBuilds = 0;

            public function contentSitemap(): string
            {
                ++$this->contentBuilds;

                return 'content-'.$this->contentBuilds;
            }

            public function sitemapIndex(): string
            {
                return 'index';
            }

            public function videoSitemap(): string
            {
                return 'video';
            }
        };
        $service = new SitemapDocumentService(
            new ArrayAdapter(),
            $builder,
            new SitemapCacheRevisionProvider($connection),
            $this->publicUrls(),
        );

        self::assertSame('content-1', $service->contentSitemap());
        self::assertSame('content-1', $service->contentSitemap());
        self::assertSame('content-2', $service->contentSitemap());
        self::assertSame(2, $builder->contentBuilds);
    }

    public function testRefreshWarmsTheSameRevisionScopedKeysUsedByPublicRoutes(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(3))
            ->method('fetchOne')
            ->willReturn(str_repeat('c', 32).'-2');
        $builder = new class implements SitemapDocumentBuilderInterface {
            public int $contentBuilds = 0;
            public int $videoBuilds = 0;

            public function contentSitemap(): string
            {
                ++$this->contentBuilds;

                return 'cached-content';
            }

            public function sitemapIndex(): string
            {
                return 'index';
            }

            public function videoSitemap(): string
            {
                ++$this->videoBuilds;

                return 'cached-video';
            }
        };
        $service = new SitemapDocumentService(
            new ArrayAdapter(),
            $builder,
            new SitemapCacheRevisionProvider($connection),
            $this->publicUrls(),
        );

        $service->refresh();

        self::assertSame('cached-content', $service->contentSitemap());
        self::assertSame('cached-video', $service->videoSitemap());
        self::assertSame(1, $builder->contentBuilds);
        self::assertSame(1, $builder->videoBuilds);
    }

    private function publicUrls(): CanonicalPublicUrlGenerator
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->expects(self::atLeastOnce())->method('generate')->willReturn('/');

        return new CanonicalPublicUrlGenerator($router, 'https://arcanorum.example.test');
    }
}
