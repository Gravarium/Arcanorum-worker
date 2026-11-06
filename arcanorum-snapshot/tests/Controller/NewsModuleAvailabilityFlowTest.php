<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\FeatureModule;
use App\Entity\NewsArticle;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Platform\Hosting\CapabilityProbeInterface;
use App\Platform\Hosting\HostingProfileDetector;
use App\Service\SiteSettingsManager;
use App\Service\SiteSettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class NewsModuleAvailabilityFlowTest extends WebTestCase
{
    public function testDisabledNewsModuleClosesPublicRoutesAndRemovesNewsFromOutputs(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->setServerParameter('REMOTE_ADDR', '2001:db8:'.bin2hex(random_bytes(2)).':'.bin2hex(random_bytes(2)).'::53');

        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $settingsProvider = $client->getContainer()->get(SiteSettingsProvider::class);
        $settingsManager = $client->getContainer()->get(SiteSettingsManager::class);
        self::assertInstanceOf(SiteSettingsProvider::class, $settingsProvider);
        self::assertInstanceOf(SiteSettingsManager::class, $settingsManager);

        $originalModuleSettings = [];
        foreach (FeatureModule::cases() as $module) {
            $originalModuleSettings[$module->inputName()] = $settingsProvider->current()->isFeatureModuleEnabled($module) ? '1' : '0';
        }

        $enabledModuleSettings = $originalModuleSettings;
        $enabledModuleSettings[FeatureModule::News->inputName()] = '1';
        $enabledModuleSettings[FeatureModule::Search->inputName()] = '1';
        $suffix = bin2hex(random_bytes(8));
        $term = 'module-check-'.$suffix;
        $newsSlug = 'module-check-news-'.$suffix;
        $newsTitle = 'Hidden module news '.$term;
        $videoTitle = 'Available module video '.$term;
        $article = new NewsArticle($newsTitle, $newsSlug, null, 'Public summary', 'Public body', PublishingStatus::Published, false, false);
        $video = new Video(
            $videoTitle,
            'Public video description',
            null,
            VideoSourceType::Url,
            'https://video.example.test/module-check-'.$suffix.'.mp4',
            PublishingStatus::Published,
            false,
            false,
        );

        try {
            self::assertSame([], $settingsManager->updateFeatureModules($enabledModuleSettings));
            $entityManager->persist($article);
            $entityManager->persist($video);
            $entityManager->flush();

            $client->request('GET', '/news/'.$newsSlug);
            self::assertResponseIsSuccessful('The published News fixture must be reachable while its module is enabled.');

            $disabledModuleSettings = $enabledModuleSettings;
            $disabledModuleSettings[FeatureModule::News->inputName()] = '0';
            self::assertSame([], $settingsManager->updateFeatureModules($disabledModuleSettings));

            foreach (['/news', '/news/category/module-check-'.$suffix, '/news/'.$newsSlug] as $path) {
                $client->request('GET', $path);
                self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, sprintf('Expected %s to be unavailable.', $path));
                self::assertPrivateNoStore($client->getResponse()->headers->get('Cache-Control'));
                self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
                self::assertSame('', $client->getResponse()->getContent());
            }

            $client->request('GET', '/news.rss');
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
            self::assertPrivateNoStore($client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
            self::assertSame('', $client->getResponse()->getContent());

            $client->request('GET', '/videos.rss');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString($videoTitle, $client->getResponse()->getContent());

            $client->request('GET', '/sitemap.xml');
            self::assertResponseIsSuccessful();
            $sitemap = $client->getResponse()->getContent();
            self::assertStringNotContainsString('/news/'.$newsSlug, $sitemap);
            self::assertStringContainsString('/videos/'.$video->getId(), $sitemap);
            $sitemapDocument = new \DOMDocument();
            self::assertTrue($sitemapDocument->loadXML($sitemap, LIBXML_NONET));

            $client->request('GET', '/sitemap-index.xml');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('/sitemap.xml', $client->getResponse()->getContent());
            self::assertStringNotContainsString('/news/'.$newsSlug, $client->getResponse()->getContent());

            $client->request('GET', '/robots.txt');
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString('/news/'.$newsSlug, $client->getResponse()->getContent());

            $client->request('GET', '/search?q='.rawurlencode($term));
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString($newsTitle, $client->getResponse()->getContent());
            self::assertStringContainsString($videoTitle, $client->getResponse()->getContent());

            $client->request('GET', '/search/suggestions?q='.rawurlencode($term));
            self::assertResponseIsSuccessful();
            $liveSuggestions = $client->getResponse()->getContent();
            self::assertStringNotContainsString($newsTitle, $liveSuggestions);
            self::assertStringContainsString($videoTitle, $liveSuggestions);

            self::assertSame([], $settingsManager->updateFeatureModules($enabledModuleSettings));
            $client->request('GET', '/news/'.$newsSlug);
            self::assertResponseIsSuccessful('Re-enabling News must restore already published content.');
            $client->request('GET', '/news.rss');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('/news/'.$newsSlug, $client->getResponse()->getContent());
            $client->request('GET', '/sitemap.xml');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('/news/'.$newsSlug, $client->getResponse()->getContent());
        } finally {
            self::assertSame([], $settingsManager->updateFeatureModules($originalModuleSettings));
            $connection->executeStatement('DELETE FROM search_term_daily WHERE day = CURRENT_DATE AND term = :term', ['term' => $term]);
            $connection->executeStatement('DELETE FROM news_articles WHERE id = :id', ['id' => $article->getId()]);
            $connection->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $video->getId()]);
        }
    }

    public function testNewsRoutesFailClosedWhenHostingCapabilityProbeThrows(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $container = $client->getContainer();
        $settingsProvider = $container->get(SiteSettingsProvider::class);
        $settingsManager = $container->get(SiteSettingsManager::class);
        self::assertInstanceOf(SiteSettingsProvider::class, $settingsProvider);
        self::assertInstanceOf(SiteSettingsManager::class, $settingsManager);

        $originalModuleSettings = [];
        foreach (FeatureModule::cases() as $module) {
            $originalModuleSettings[$module->inputName()] = $settingsProvider->current()->isFeatureModuleEnabled($module) ? '1' : '0';
        }

        $enabledModuleSettings = $originalModuleSettings;
        $enabledModuleSettings[FeatureModule::News->inputName()] = '1';

        try {
            self::assertSame([], $settingsManager->updateFeatureModules($enabledModuleSettings));

            $throwingProbe = new class implements CapabilityProbeInterface {
                public function probe(): iterable
                {
                    throw new \RuntimeException('Capability probe diagnostic marker.');
                }
            };
            $container->set(HostingProfileDetector::class, new HostingProfileDetector([$throwingProbe]));

            $client->request('GET', '/');
            self::assertResponseIsSuccessful('A failed capability check must not disable the public platform home page.');
            $homeBody = $client->getResponse()->getContent();
            if (!is_string($homeBody)) {
                throw new \RuntimeException('The platform home response body must be readable.');
            }
            self::assertStringNotContainsString('Capability probe diagnostic marker', $homeBody);

            foreach (['/news', '/news/category/g54-unavailable', '/news/g54-unavailable', '/news.rss'] as $path) {
                $client->request('GET', $path);
                self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, sprintf('Expected %s to remain unavailable.', $path));
                self::assertPrivateNoStore($client->getResponse()->headers->get('Cache-Control'));
                self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
                $body = $client->getResponse()->getContent();
                self::assertIsString($body);
                self::assertSame('', $body);
            }
        } finally {
            self::assertSame([], $settingsManager->updateFeatureModules($originalModuleSettings));
        }
    }

    private static function assertPrivateNoStore(?string $cacheControl): void
    {
        self::assertNotNull($cacheControl);
        $directives = array_map(trim(...), explode(',', strtolower($cacheControl)));
        self::assertContains('private', $directives);
        self::assertContains('no-store', $directives);
    }
}
