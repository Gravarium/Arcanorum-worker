<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\NewsArticle;
use App\Entity\PublishingStatus;
use App\Module\Feed\Service\CanonicalPublicUrlGenerator;
use App\Service\SiteSettingsManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ExternalCommentProviderTest extends WebTestCase
{
    public function testDisqusLoadsOnlyAfterSignedOptionalConsentAndUsesSafeCanonicalUrl(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $urlGenerator = $client->getContainer()->get(UrlGeneratorInterface::class);
        self::assertInstanceOf(UrlGeneratorInterface::class, $urlGenerator);
        $client->getContainer()->set(
            CanonicalPublicUrlGenerator::class,
            new CanonicalPublicUrlGenerator($urlGenerator, 'https://arcanorum.example.test'),
        );

        $slug = 'external-comments-'.bin2hex(random_bytes(8));
        $article = new NewsArticle(
            'Consent-gated comments',
            $slug,
            null,
            null,
            'Public test body.',
            PublishingStatus::Published,
            false,
            true,
        );
        $entityManager->persist($article);
        $entityManager->flush();

        $settings = $client->getContainer()->get(SiteSettingsManager::class);
        self::assertInstanceOf(SiteSettingsManager::class, $settings);
        self::assertSame([], $settings->update([
            'site_name' => 'Arcanorum',
            'tagline' => '',
            'locale' => 'de',
            'timezone' => 'Europe/Berlin',
            'comment_provider' => 'disqus',
            'disqus_comments_shortname' => 'arcanorum-community',
            'cookie_consent_enabled' => '1',
        ]));

        try {
            $client->request('GET', '/news/'.$slug.'?tracking=private-value');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('a[href*="cookie-einstellungen"]');
            $preferenceLinks = $client->getCrawler()->filter('a[href*="return_to"]');
            self::assertGreaterThan(0, $preferenceLinks->count());
            $preferenceLink = $preferenceLinks->first();
            $preferenceQuery = [];
            parse_str((string) parse_url((string) $preferenceLink->attr('href'), PHP_URL_QUERY), $preferenceQuery);
            self::assertSame('/news/'.$slug, $preferenceQuery['return_to'] ?? null);
            self::assertStringNotContainsString('private-value', (string) $preferenceLink->attr('href'));
            self::assertSelectorNotExists('script[src*="external-comments"]');
            self::assertStringNotContainsString('disqus.com', (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('https://connect.facebook.net', (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('https://connect.facebook.net', (string) $client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));
            $token = (string) $client->getCrawler()->filter('.cookie-consent-banner input[name="token"]')->attr('value');

            $client->request('POST', '/cookie-einstellungen', [
                'token' => $token,
                'choice' => 'all',
                'return_to' => '/news/'.$slug,
            ]);
            self::assertResponseRedirects('/news/'.$slug);

            $client->request('GET', '/news/'.$slug);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('[data-external-comments-provider="disqus"]');
            self::assertSelectorExists('[data-disqus-shortname="arcanorum-community"]');
            self::assertSelectorExists('[data-disqus-page-url="https://arcanorum.example.test/news/'.$slug.'"]');
            self::assertSelectorExists('script[src*="external-comments"]');
            self::assertStringContainsString('https://arcanorum.example.test/news/'.$slug, (string) $client->getResponse()->getContent());
            self::assertStringContainsString('script-src', (string) $client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));
            self::assertStringContainsString('https://arcanorum-community.disqus.com', (string) $client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));
            self::assertFalse($client->getResponse()->headers->has('Content-Security-Policy'));

            self::assertSame([], $settings->update([
                'site_name' => 'Arcanorum',
                'tagline' => '',
                'locale' => 'de',
                'timezone' => 'Europe/Berlin',
                'comment_provider' => 'facebook',
                'facebook_comments_app_id' => '123456789012345',
                'cookie_consent_enabled' => '1',
            ]));
            $client->request('GET', '/news/'.$slug);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('[data-external-comments-provider="facebook"]');
            self::assertSelectorExists('[data-facebook-app-id="123456789012345"]');
            self::assertSelectorExists('.fb-comments[data-href="https://arcanorum.example.test/news/'.$slug.'"]');
            self::assertStringContainsString('https://connect.facebook.net', (string) $client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));
            self::assertFalse($client->getResponse()->headers->has('Content-Security-Policy'));
        } finally {
            $settings->update([
                'site_name' => 'Arcanorum',
                'tagline' => '',
                'locale' => 'de',
                'timezone' => 'Europe/Berlin',
                'comment_provider' => 'native',
                'facebook_comments_app_id' => '',
                'disqus_comments_shortname' => '',
                'cookie_consent_enabled' => '1',
            ]);
            $entityManager->getConnection()->executeStatement('DELETE FROM news_articles WHERE slug = :slug', ['slug' => $slug]);
        }
    }
}
