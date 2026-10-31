<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserCookie;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

final class CookieConsentControllerTest extends WebTestCase
{
    public function testConsentCsrfCookieIsSecureOnHttps(): void
    {
        $client = static::createClient();
        $this->resetConsentSettings($client);

        $client->request('GET', '/', server: ['HTTPS' => 'on', 'HTTP_HOST' => 'localhost']);
        self::assertResponseIsSuccessful();
        $csrfCookie = $this->responseCookie($client, 'arcanorum_consent_csrf');
        self::assertInstanceOf(Cookie::class, $csrfCookie);
        self::assertTrue($csrfCookie->isSecure());
        self::assertTrue($csrfCookie->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_STRICT, $csrfCookie->getSameSite());
        $this->resetConsentSettings($client);
    }

    public function testConsentRequiresValidChoiceAndSameOriginTokenAndCanBeWithdrawn(): void
    {
        $client = static::createClient();
        $this->resetConsentSettings($client);

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.cookie-consent-banner');
        self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $token = (string) $client->getCrawler()->filter('.cookie-consent-banner input[name="token"]')->attr('value');
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $token);
        $csrfCookie = $this->responseCookie($client, 'arcanorum_consent_csrf');
        self::assertInstanceOf(Cookie::class, $csrfCookie);
        self::assertTrue($csrfCookie->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_STRICT, $csrfCookie->getSameSite());
        self::assertSame('/', $csrfCookie->getPath());
        self::assertNull($csrfCookie->getDomain());

        $client->request('POST', '/cookie-einstellungen', ['token' => $token, 'choice' => 'maybe']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertNull($client->getCookieJar()->get('arcanorum_cookie_consent'));

        $client->request('POST', '/cookie-einstellungen', ['token' => $token, 'choice' => 'necessary'], server: [
            'HTTP_ORIGIN' => 'https://untrusted.example',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertNull($client->getCookieJar()->get('arcanorum_cookie_consent'));

        $client->request('POST', '/cookie-einstellungen', ['token' => $token, 'choice' => 'necessary'], server: [
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertNull($client->getCookieJar()->get('arcanorum_cookie_consent'));

        $client->request('POST', '/cookie-einstellungen', ['token' => $token, 'choice' => 'all']);
        self::assertResponseRedirects('/');
        $consentCookie = $this->responseCookie($client, 'arcanorum_cookie_consent');
        self::assertInstanceOf(Cookie::class, $consentCookie);
        self::assertTrue($consentCookie->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_LAX, $consentCookie->getSameSite());
        self::assertSame('/', $consentCookie->getPath());
        self::assertNull($consentCookie->getDomain());
        self::assertNull($client->getCookieJar()->get('arcanorum_consent_csrf'));

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.cookie-consent-banner');

        $jarCookie = $this->jarCookie($client, 'arcanorum_cookie_consent');
        $client->getCookieJar()->set(new BrowserCookie(
            $jarCookie->getName(),
            $jarCookie->getValue().'tampered',
            $jarCookie->getExpiresTime(),
            $jarCookie->getPath(),
            $jarCookie->getDomain(),
            $jarCookie->isSecure(),
            $jarCookie->isHttpOnly(),
            false,
            $jarCookie->getSameSite(),
        ));
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.cookie-consent-banner');

        $client->request('GET', '/cookie-einstellungen');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('Auswahl speichern', (string) $client->getResponse()->getContent());

        $preferencesToken = (string) $client->getCrawler()->filter('form[action="/cookie-einstellungen"] input[name="token"]')->attr('value');
        $client->request('POST', '/cookie-einstellungen', ['token' => $preferencesToken, 'choice' => 'necessary']);
        self::assertResponseRedirects('/');
        $necessaryCookie = $client->getCookieJar()->get('arcanorum_cookie_consent');
        self::assertInstanceOf(BrowserCookie::class, $necessaryCookie);
        self::assertStringContainsString('.necessary.', $necessaryCookie->getValue());

        $this->resetConsentSettings($client);
    }

    public function testConsentReturnsToAnInternalCommentPageAndRejectsExternalRedirects(): void
    {
        $client = static::createClient();
        $this->resetConsentSettings($client);

        $client->request('GET', '/videos');
        self::assertResponseIsSuccessful();
        $token = (string) $client->getCrawler()->filter('.cookie-consent-banner input[name="token"]')->attr('value');
        $client->request('POST', '/cookie-einstellungen', [
            'token' => $token,
            'choice' => 'all',
            'return_to' => '/news/example-story',
        ]);
        self::assertResponseRedirects('/news/example-story');

        $client->request('GET', '/cookie-einstellungen');
        self::assertResponseIsSuccessful();
        $preferencesToken = (string) $client->getCrawler()->filter('form[action="/cookie-einstellungen"] input[name="token"]')->attr('value');
        $client->request('POST', '/cookie-einstellungen', [
            'token' => $preferencesToken,
            'choice' => 'necessary',
            'return_to' => '//attacker.example/collect',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertNull($client->getResponse()->headers->get('Location'));

        $client->request('POST', '/cookie-einstellungen', [
            'token' => $preferencesToken,
            'choice' => 'necessary',
            'return_to' => '/konto/passwort-zuruecksetzen/'.str_repeat('A', 43),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertNull($client->getResponse()->headers->get('Location'));

        $client->request('POST', '/cookie-einstellungen', [
            'token' => $preferencesToken,
            'choice' => 'necessary',
            'return_to' => '/news/example-story?token=secret',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertNull($client->getResponse()->headers->get('Location'));

        $this->resetConsentSettings($client);
    }

    public function testWithdrawingOptionalConsentExpiresOnlyGoogleAnalyticsCookies(): void
    {
        $client = static::createClient();
        $this->resetConsentSettings($client);
        $client->getCookieJar()->set(new BrowserCookie('_ga', 'GA1.1.123.456', null, '/', '', false, false, false, 'lax'));
        $client->getCookieJar()->set(new BrowserCookie('_ga_ABC1234567', 'GS1.1.123.456', null, '/', '', false, false, false, 'lax'));
        $client->getCookieJar()->set(new BrowserCookie('unrelated_cookie', 'keep', null, '/', '', false, false, false, 'lax'));

        $client->request('GET', '/cookie-einstellungen');
        self::assertResponseIsSuccessful();
        $token = (string) $client->getCrawler()->filter('form[action="/cookie-einstellungen"] input[name="token"]')->attr('value');
        $client->request('POST', '/cookie-einstellungen', ['token' => $token, 'choice' => 'necessary']);

        self::assertResponseRedirects('/');
        foreach (['_ga', '_ga_ABC1234567'] as $name) {
            $expired = $this->responseCookie($client, $name);
            self::assertInstanceOf(Cookie::class, $expired);
            self::assertSame('', $expired->getValue());
            self::assertLessThanOrEqual(1, $expired->getExpiresTime());
        }
        self::assertNull($this->responseCookie($client, 'unrelated_cookie'));

        $this->resetConsentSettings($client);
    }

    private function resetConsentSettings(KernelBrowser $client): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->getConnection()->executeStatement(
            "UPDATE site_settings SET cookie_consent_enabled = TRUE, cookie_consent_message = 'Wir verwenden notwendige Cookies für Anmeldung, Sicherheit und die Ansichtspräferenz in diesem Browser. Optionale Analyse- und Werbedienste werden nur nach Ihrer Zustimmung geladen.', google_analytics_measurement_id = '', maintenance_mode_enabled = FALSE, search_indexing_enabled = TRUE, seo_description = '' WHERE id = 1",
        );
    }

    private function responseCookie(KernelBrowser $client, string $name): ?Cookie
    {
        foreach ($client->getResponse()->headers->getCookies() as $cookie) {
            if ($name === $cookie->getName()) {
                return $cookie;
            }
        }

        return null;
    }

    private function jarCookie(KernelBrowser $client, string $name): BrowserCookie
    {
        foreach ($client->getCookieJar()->all() as $cookie) {
            if ($name === $cookie->getName()) {
                return $cookie;
            }
        }

        self::fail('Der gespeicherte Testcookie fehlt.');
    }
}
