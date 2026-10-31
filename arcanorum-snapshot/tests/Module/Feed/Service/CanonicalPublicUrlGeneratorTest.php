<?php

declare(strict_types=1);

namespace App\Tests\Module\Feed\Service;

use App\Module\Feed\Service\CanonicalPublicUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;

final class CanonicalPublicUrlGeneratorTest extends TestCase
{
    public function testBuildsUrlsFromTheConfiguredHttpsOrigin(): void
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->expects(self::once())
            ->method('generate')
            ->with('target', [], UrlGeneratorInterface::ABSOLUTE_PATH)
            ->willReturn('/target');

        $generator = new CanonicalPublicUrlGenerator($router, 'https://Arcanorum.Example.test:443/');

        self::assertTrue($generator->isConfigured());
        self::assertSame('https://arcanorum.example.test/target', $generator->generate('target'));
    }

    public function testRejectsUnsafeOrIncompletePublicOrigins(): void
    {
        $router = $this->inertRouter();
        foreach ([
            '',
            'http://arcanorum.example.test',
            'https://user@arcanorum.example.test',
            'https://arcanorum.example.test/path',
            'https://arcanorum.example.test?host=attacker.example.test',
            'https://arcanorum.example.test#fragment',
            'https://arcanorum.example.test:65536',
            "https://arcanorum.example.test\n.attacker.example.test",
        ] as $origin) {
            $generator = new CanonicalPublicUrlGenerator($router, $origin);
            self::assertFalse($generator->isConfigured(), $origin);
        }
    }

    public function testRejectsRouterOutputThatCouldOverrideTheConfiguredOrigin(): void
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->expects(self::once())
            ->method('generate')
            ->with('target', [], UrlGeneratorInterface::ABSOLUTE_PATH)
            ->willReturn('//attacker.example.test/path');
        $generator = new CanonicalPublicUrlGenerator($router, 'https://arcanorum.example.test');

        $this->expectException(\LogicException::class);
        $generator->generate('target');
    }

    private function inertRouter(): UrlGeneratorInterface
    {
        return new UrlGenerator(new RouteCollection(), new RequestContext());
    }
}
