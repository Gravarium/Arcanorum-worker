<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Platform\Hosting\HostingCachePoolProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\NamespacedPoolInterface;

final class HomePageTest extends WebTestCase
{
    public function testFrameworkApplicationCacheUsesHostingProvider(): void
    {
        static::createClient();

        $cache = static::getContainer()->get('cache.app');

        self::assertInstanceOf(HostingCachePoolProvider::class, $cache);
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
        self::assertInstanceOf(CacheInterface::class, $cache);
        self::assertInstanceOf(NamespacedPoolInterface::class, $cache);

        $key = 'framework-cache-'.bin2hex(random_bytes(8));
        $item = $cache->getItem($key);
        $item->set('active');
        self::assertTrue($cache->save($item));
        self::assertSame('active', $cache->getItem($key)->get());
        self::assertTrue($cache->deleteItem($key));
    }

    public function testPlatformHomePageLoads(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('h1', 'Arcanorum');
        self::assertSelectorTextContains('main', 'Neueste Videos');
    }

    public function testPublicNavigationLeadsGuestsToMemberLoginAndRegistration(): void
    {
        $client = static::createClient();

        foreach (['/', '/news', '/videos'] as $path) {
            $client->request('GET', $path);

            self::assertResponseIsSuccessful();
            self::assertSelectorExists('nav.public-navigation a[href="/konto/anmelden"]');
            self::assertSelectorExists('nav.public-navigation a[href="/konto/registrieren"]');
        }

        $client->request('GET', '/konto/profil');
        self::assertResponseRedirects('/konto/anmelden');

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/konto/registrieren"]');
    }
}
