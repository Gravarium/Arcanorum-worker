<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HttpFoundationTest extends WebTestCase
{
    public function testUnknownRouteReturnsASafeErrorPage(): void
    {
        $client = self::createClient();
        $client->request('GET', '/foundation-unknown-route?probe=%3Cscript%3Ealert(1)%3C/script%3E', server: ['HTTP_ACCEPT' => 'text/html']);

        self::assertResponseStatusCodeSame(404);
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringContainsString('Anfrage nicht verfügbar', $content);
        self::assertStringNotContainsString('<script>', $content);
        self::assertStringNotContainsString('Stack Trace', $content);
        self::assertStringNotContainsString('DATABASE_URL', $content);
    }
}
