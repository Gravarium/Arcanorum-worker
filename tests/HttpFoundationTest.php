<?php

declare(strict_types=1);

namespace App\Tests;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class HttpFoundationTest extends WebTestCase
{
    public function testUnknownRouteReturnsASafeErrorPage(): void
    {
        $client = self::createClient();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('log')->with(
            LogLevel::ERROR,
            self::stringContains('NotFoundHttpException'),
            self::callback(static fn (mixed $context): bool => is_array($context) && ($context['exception'] ?? null) instanceof NotFoundHttpException),
        );
        self::getContainer()->set('logger', $logger);
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
