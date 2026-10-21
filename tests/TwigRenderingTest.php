<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class TwigRenderingTest extends KernelTestCase
{
    public function testErrorTemplateEscapesDynamicValues(): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $html = $twig->render('bundles/TwigBundle/Exception/error.html.twig', ['status_code' => '<script>alert(1)</script>']);

        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
    }
}
