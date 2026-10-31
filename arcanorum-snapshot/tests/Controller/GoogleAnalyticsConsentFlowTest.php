<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GoogleAnalyticsConsentFlowTest extends WebTestCase
{
    public function testTrackingLoadsOnlyAfterOptionalConsentOnAllowlistedPublicRoutes(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->executeStatement("UPDATE site_settings SET google_analytics_measurement_id = 'G-ABC1234567', cookie_consent_enabled = FALSE WHERE id = 1");
        $entityManager->clear();

        try {
            $client->request('GET', '/search?q=private-term');
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('script[data-measurement-id]');
            self::assertStringNotContainsString('www.googletagmanager.com', (string) $client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));

            $connection->executeStatement('UPDATE site_settings SET cookie_consent_enabled = TRUE WHERE id = 1');
            $entityManager->clear();
            $client->request('GET', '/search?q=private-term');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('.cookie-consent-banner');
            self::assertSelectorNotExists('script[data-measurement-id]');
            self::assertContains('Cookie', $client->getResponse()->getVary());
            self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

            $token = (string) $client->getCrawler()->filter('.cookie-consent-banner input[name="token"]')->attr('value');
            $client->request('POST', '/cookie-einstellungen', ['token' => $token, 'choice' => 'all']);
            self::assertResponseRedirects('/');

            $client->request('GET', '/search?q=private-term');
            self::assertResponseIsSuccessful();
            $script = $client->getCrawler()->filter('script[data-measurement-id="G-ABC1234567"]');
            self::assertCount(1, $script);
            self::assertSame('search', $script->attr('data-page-type'));
            self::assertStringContainsString('analytics/google-analytics.js', (string) $script->attr('src'));
            self::assertSame('no-referrer', $script->attr('referrerpolicy'));
            self::assertContains('Cookie', $client->getResponse()->getVary());
            self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

            $policy = (string) $client->getResponse()->headers->get('Content-Security-Policy-Report-Only');
            self::assertStringContainsString('https://www.googletagmanager.com', $policy);
            self::assertStringContainsString('https://www.google-analytics.com', $policy);
            self::assertStringContainsString('https://region1.google-analytics.com', $policy);

            $client->request('GET', '/videos/categories');
            self::assertResponseIsSuccessful();
            $categoryScript = $client->getCrawler()->filter('script[data-measurement-id="G-ABC1234567"]');
            self::assertCount(1, $categoryScript);
            self::assertSame('video_categories', $categoryScript->attr('data-page-type'));
            self::assertStringContainsString('https://www.googletagmanager.com', (string) $client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));

            $client->request('GET', '/mitglieder');
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('script[data-measurement-id]');

            foreach (['/news.rss', '/videos.rss'] as $feedPath) {
                $client->request('GET', $feedPath);
                self::assertResponseIsSuccessful();
                self::assertStringNotContainsString('googletagmanager.com', (string) $client->getResponse()->getContent());
            }

            $client->request('GET', '/admin/login');
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('script[data-measurement-id]');
            self::assertStringNotContainsString('www.googletagmanager.com', (string) $client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));

            $client->request('GET', '/cookie-einstellungen');
            self::assertResponseIsSuccessful();
            $token = (string) $client->getCrawler()->filter('form[action="/cookie-einstellungen"] input[name="token"]')->attr('value');
            $client->request('POST', '/cookie-einstellungen', ['token' => $token, 'choice' => 'necessary']);
            self::assertResponseRedirects('/');

            $client->request('GET', '/search?q=private-term');
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('script[data-measurement-id]');
            self::assertStringNotContainsString('www.googletagmanager.com', (string) $client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));
        } finally {
            $connection->executeStatement("UPDATE site_settings SET google_analytics_measurement_id = '', cookie_consent_enabled = TRUE WHERE id = 1");
            $entityManager->clear();
        }
    }
}
