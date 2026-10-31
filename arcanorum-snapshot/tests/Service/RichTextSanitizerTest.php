<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\RichTextSanitizer;
use PHPUnit\Framework\TestCase;

final class RichTextSanitizerTest extends TestCase
{
    public function testKeepsEditorialFormattingAndRemovesActiveContentAndUnsafeAttributes(): void
    {
        $sanitizer = new RichTextSanitizer();

        $clean = $sanitizer->sanitize(
            '<p onclick="alert(1)">A <strong>formatted</strong> story</p>'
            .'<script>alert(2)</script><iframe src="https://evil.example.test">frame text</iframe>'
            .'<img src="x" onerror="alert(3)"><a href="javascript:alert(4)" target="_blank">unsafe link</a>',
        );

        self::assertStringContainsString('<p>A <strong>formatted</strong> story</p>', $clean);
        self::assertStringContainsString('unsafe link', $clean);
        self::assertStringNotContainsString('<script', $clean);
        self::assertStringNotContainsString('alert(', $clean);
        self::assertStringNotContainsString('<iframe', $clean);
        self::assertStringNotContainsString('<img', $clean);
        self::assertStringNotContainsString('onclick', $clean);
        self::assertStringNotContainsString('onerror', $clean);
        self::assertStringNotContainsString('javascript:', $clean);
        self::assertStringNotContainsString('target=', $clean);
    }

    public function testKeepsSafeLinksAndAddsSafeLinkRelation(): void
    {
        $sanitizer = new RichTextSanitizer();

        $clean = $sanitizer->sanitize('<p><a href="https://example.test/story" target="_blank">Read</a></p>');

        self::assertStringContainsString('href="https://example.test/story"', $clean);
        self::assertStringContainsString('rel="nofollow noopener noreferrer"', $clean);
        self::assertStringNotContainsString('target=', $clean);
    }

    public function testAllowsOnlyLocalOwnedInlineImageRoutesAndReturnsUniqueImageIds(): void
    {
        $firstId = str_repeat('a', 64);
        $secondId = str_repeat('b', 64);
        $sanitizer = new RichTextSanitizer();
        $html = '<p>Text</p>'
            .'<img src="/content-inline-images/'.$firstId.'" alt="First &amp; safe" onerror="alert(1)">'
            .'<img src="/content-inline-images/'.$secondId.'" alt="Second">'
            .'<img src="https://remote.example.test/image.png" alt="Remote">'
            .'<img src="//remote.example.test/image.png" alt="Protocol relative">'
            .'<img src="data:image/png;base64,AAAA" alt="Data">';

        $clean = $sanitizer->sanitize($html);

        self::assertStringContainsString('<img src="/content-inline-images/'.$firstId.'" alt="First &amp; safe">', $clean);
        self::assertStringContainsString('<img src="/content-inline-images/'.$secondId.'" alt="Second">', $clean);
        self::assertStringNotContainsString('remote.example.test', $clean);
        self::assertStringNotContainsString('data:image', $clean);
        self::assertStringNotContainsString('onerror', $clean);
        self::assertSame([$firstId, $secondId], $sanitizer->inlineImageIds($html));
    }

    public function testDropsMalformedOrNonLocalImagePaths(): void
    {
        $clean = new RichTextSanitizer()->sanitize(
            '<img src="/content-inline-images/not-an-id" alt="Bad">'
            .'<img src="/other/'.str_repeat('c', 64).'" alt="Wrong route">'
            .'<img src="/content-inline-images/'.str_repeat('d', 64).'?download=1" alt="Query">',
        );

        self::assertStringNotContainsString('<img', $clean);
        self::assertSame([], new RichTextSanitizer()->inlineImageIds($clean));
    }

    public function testRejectsInvalidUtf8(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RichTextSanitizer()->sanitize("\xC3\x28");
    }

    public function testRejectsOversizedEditorPayloads(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RichTextSanitizer()->sanitize(str_repeat('a', 1_000_001));
    }

    public function testDisplayFilterFailsClosedForInvalidStoredText(): void
    {
        self::assertSame('', (string) new RichTextSanitizer()->render("\xC3\x28"));
    }
}
