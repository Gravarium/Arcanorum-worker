<?php

declare(strict_types=1);

namespace App\Tests\Module\Advertising\Domain;

use App\Module\Advertising\Domain\SafeAssetPath;
use App\Module\Advertising\Domain\SafeDestination;
use App\Module\Advertising\Domain\SafeHtml;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SafeContentTest extends TestCase
{
    public function testHtmlBannerRemovesExecutableMarkupAndUnsafeAttributes(): void
    {
        $safe = SafeHtml::sanitize('<div onclick="run()"><img src="/media/banner.png" alt="Banner" onerror="run()"><script>alert(1)</script><a href="javascript:alert(2)">Link</a><svg onload="run()"><script>alert(3)</script></svg><p style="background:url(javascript:alert(4))">Sicher</p></div>');

        self::assertStringContainsString('<img src="/media/banner.png" alt="Banner"', $safe->html);
        self::assertStringContainsString('Sicher', $safe->html);
        self::assertStringNotContainsString('<script', strtolower($safe->html));
        self::assertStringNotContainsString('<svg', strtolower($safe->html));
        self::assertStringNotContainsString('onclick', strtolower($safe->html));
        self::assertStringNotContainsString('onerror', strtolower($safe->html));
        self::assertStringNotContainsString('javascript:', strtolower($safe->html));
        self::assertStringNotContainsString('style=', strtolower($safe->html));
    }

    public function testHtmlBannerRejectsOversizedInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SafeHtml::sanitize(str_repeat('x', 50001));
    }

    public function testSafeDestinationAcceptsHttpsOnly(): void
    {
        self::assertSame('https://example.invalid/path?q=one', SafeDestination::fromString('https://example.invalid/path?q=one')->url);
    }

    #[DataProvider('unsafeDestinations')]
    public function testUnsafeRedirectTargetsAreRejected(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SafeDestination::fromString($url);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeDestinations(): iterable
    {
        yield 'javascript scheme' => ['javascript:alert(1)'];
        yield 'data scheme' => ['data:text/html,<script>run()</script>'];
        yield 'foreign scheme' => ['ftp://example.invalid/file'];
        yield 'userinfo' => ['https://trusted.invalid@evil.invalid/'];
        yield 'relative path' => ['/relative/path'];
        yield 'control characters' => ["https://example.invalid/\r\nLocation:evil"];
    }

    #[DataProvider('unsafeAssetPaths')]
    public function testAssetPathsRejectExternalSchemesAndTraversal(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SafeAssetPath::fromString($path);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeAssetPaths(): iterable
    {
        yield 'foreign scheme' => ['https://assets.example.invalid/banner.png'];
        yield 'protocol relative' => ['//assets.example.invalid/banner.png'];
        yield 'parent traversal' => ['/media/../private/banner.png'];
        yield 'backslash' => ['/media\\banner.png'];
        yield 'query' => ['/media/banner.png?track=1'];
    }
}
