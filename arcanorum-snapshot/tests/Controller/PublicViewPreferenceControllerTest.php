<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserCookie;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

final class PublicViewPreferenceControllerTest extends WebTestCase
{
    public function testPublicPagesOfferCompactAndDesktopViewsWithoutChangingTheSiteDefault(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form.site-view-preferences[action="/public-view-preference"][method="post"]');
        self::assertSelectorExists('html[data-public-view="site"]');
        self::assertSelectorExists('select[name="mode"] option[value="compact"]');
        self::assertSelectorExists('select[name="mode"] option[value="desktop"]');

        $client->request('POST', 'https://localhost/public-view-preference', ['mode' => 'compact', 'return_to' => '/videos'], server: [
            'HTTPS' => 'on',
            'HTTP_HOST' => 'localhost',
            'HTTP_ORIGIN' => 'https://localhost',
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ]);

        self::assertResponseRedirects('/videos', Response::HTTP_SEE_OTHER);
        self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $cookie = $this->responseCookie($client, 'arcanorum_public_view');
        self::assertInstanceOf(Cookie::class, $cookie);
        self::assertSame('compact', $cookie->getValue());
        self::assertTrue($cookie->isSecure());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        self::assertSame('/', $cookie->getPath());
        self::assertNull($cookie->getDomain());

        $client->request('GET', 'https://localhost/', server: ['HTTPS' => 'on', 'HTTP_HOST' => 'localhost']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[data-public-view="compact"][data-public-layout="compact"]');
        self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('Cookie', (string) $client->getResponse()->headers->get('Vary'));
    }

    public function testViewSwitchKeepsSafePublicPageTargetAndDropsSearchTerms(): void
    {
        $client = static::createClient();
        $client->request('GET', '/search?q=private%20term');

        self::assertResponseIsSuccessful();
        self::assertSame(
            '/search',
            $client->getCrawler()->filter('.site-view-preferences input[name="return_to"]')->attr('value'),
        );
    }

    public function testSameOriginRefererSupportsLegacyClientsAndDefaultPorts(): void
    {
        $client = static::createClient();
        $client->request('POST', 'https://localhost:443/public-view-preference', ['mode' => 'desktop', 'return_to' => '/'], server: [
            'HTTPS' => 'on',
            'HTTP_HOST' => 'localhost:443',
            'HTTP_REFERER' => 'https://localhost:443/videos',
        ]);

        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);
        self::assertSame('desktop', $this->responseCookie($client, 'arcanorum_public_view')?->getValue());
    }

    public function testInvalidPreferenceCookieIsIgnoredAndAdminPagesDoNotShowThePublicControl(): void
    {
        $client = static::createClient();
        $client->getCookieJar()->set(new BrowserCookie('arcanorum_public_view', 'wide-everything'));
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[data-public-view="site"]');
        self::assertStringNotContainsString('Cookie', (string) $client->getResponse()->headers->get('Vary'));

        $client->request('GET', '/admin/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.site-view-preferences');
    }

    public function testDesktopViewCanBeSelectedAndSiteDefaultRestored(): void
    {
        $client = static::createClient();
        $headers = [
            'HTTPS' => 'on',
            'HTTP_HOST' => 'localhost',
            'HTTP_ORIGIN' => 'https://localhost',
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ];

        $client->request('POST', 'https://localhost/public-view-preference', ['mode' => 'desktop', 'return_to' => '/news'], server: $headers);
        self::assertResponseRedirects('/news', Response::HTTP_SEE_OTHER);
        self::assertSame('desktop', $this->responseCookie($client, 'arcanorum_public_view')?->getValue());

        $client->request('GET', 'https://localhost/', server: ['HTTPS' => 'on', 'HTTP_HOST' => 'localhost']);
        self::assertSelectorExists('html[data-public-view="desktop"][data-public-layout="grid"]');
        self::assertSelectorExists('select[name="mode"] option[value="desktop"][selected]');

        $client->request('POST', 'https://localhost/public-view-preference', ['mode' => 'site', 'return_to' => '/'], server: $headers);
        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);
        $expiredCookie = $this->responseCookie($client, 'arcanorum_public_view');
        self::assertInstanceOf(Cookie::class, $expiredCookie);
        self::assertSame('', $expiredCookie->getValue());
        self::assertLessThanOrEqual(1, $expiredCookie->getExpiresTime());
    }

    public function testPreferenceRejectsCrossOriginRequestsInvalidModesAndUnsafeReturnTargets(): void
    {
        $client = static::createClient();
        $client->request('POST', 'https://localhost/public-view-preference', ['mode' => 'desktop', 'return_to' => '/'], server: [
            'HTTP_HOST' => 'localhost',
            'HTTP_ORIGIN' => 'https://attacker.example',
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertNull($this->responseCookie($client, 'arcanorum_public_view'));

        // BrowserKit uses the previous request as Referer unless its history is reset.
        $client->restart();
        $client->request('POST', '/public-view-preference', ['mode' => 'desktop', 'return_to' => '/'], server: [
            'HTTP_HOST' => 'localhost',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $headers = [
            'HTTP_HOST' => 'localhost',
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ];
        $client->request('POST', '/public-view-preference', ['mode' => 'unknown', 'return_to' => '/'], server: $headers);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertNull($this->responseCookie($client, 'arcanorum_public_view'));

        $client->request('POST', '/public-view-preference', [
            'mode' => 'desktop',
            'return_to' => '//attacker.example/collect',
        ], server: $headers);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertNull($client->getResponse()->headers->get('Location'));

        $client->request('POST', '/public-view-preference', [
            'mode' => 'desktop',
            'return_to' => '/news?token=secret',
        ], server: $headers);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $client->request('POST', '/public-view-preference', [
            'mode' => 'desktop',
            'return_to' => '/',
            'unexpected' => 'value',
        ], server: $headers);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function responseCookie(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $name): ?Cookie
    {
        foreach ($client->getResponse()->headers->getCookies() as $cookie) {
            if ($name === $cookie->getName()) {
                return $cookie;
            }
        }

        return null;
    }
}
