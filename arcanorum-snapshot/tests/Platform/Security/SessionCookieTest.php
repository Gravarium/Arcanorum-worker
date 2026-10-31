<?php

declare(strict_types=1);

namespace App\Tests\Platform\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorageFactory;

final class SessionCookieTest extends WebTestCase
{
    public function testHttpSessionCookieIsHttpOnlyAndUsesLaxSameSite(): void
    {
        $client = static::createClient();
        $client->request('GET', 'http://arcanorum.example/admin/login');

        $cookie = $client->getCookieJar()->get('MOCKSESSID');
        self::assertNotNull($cookie);
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('lax', strtolower((string) $cookie->getSameSite()));
        self::assertFalse($cookie->isSecure());
    }

    public function testAutomaticSecureCookieTracksHttpAndHttpsRequests(): void
    {
        $client = static::createClient();
        $sessionOptions = $client->getContainer()->getParameter('session.storage.options');
        if (!is_array($sessionOptions)) {
            throw new \LogicException('Sessionoptionen müssen als Array vorliegen.');
        }
        self::assertSame('auto', $sessionOptions['cookie_secure'] ?? null);

        $previousCookieSecure = ini_get('session.cookie_secure');

        try {
            ini_set('session.cookie_secure', '0');
            $factory = new NativeSessionStorageFactory(['cookie_secure' => 'auto'], null, null, true);

            $factory->createStorage(Request::create('http://arcanorum.example/'));
            self::assertSame('0', ini_get('session.cookie_secure'));

            $factory->createStorage(Request::create('https://arcanorum.example/'));
            self::assertSame('1', ini_get('session.cookie_secure'));
        } finally {
            if (false !== $previousCookieSecure) {
                ini_set('session.cookie_secure', $previousCookieSecure);
            }
        }
    }
}
