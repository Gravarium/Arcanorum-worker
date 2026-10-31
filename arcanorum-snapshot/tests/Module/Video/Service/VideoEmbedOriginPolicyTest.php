<?php

declare(strict_types=1);

namespace App\Tests\Module\Video\Service;

use App\Module\Video\Service\VideoEmbedOriginPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VideoEmbedOriginPolicyTest extends TestCase
{
    public function testParsesOnlyExactHttpsOriginsAndNormalizesHostCaseAndDefaultPort(): void
    {
        $policy = new VideoEmbedOriginPolicy();

        self::assertSame(
            ['https://publisher.example.org', 'https://video.example.net:8443'],
            $policy->parseInput(" HTTPS://Publisher.Example.ORG:443 \nhttps://video.example.net:8443\n"),
        );
        self::assertSame([], $policy->parseInput("\n \n"));
    }

    #[DataProvider('invalidOrigins')]
    public function testRejectsMalformedOrOverbroadOrigins(string $origin): void
    {
        self::assertNull(new VideoEmbedOriginPolicy()->parseInput($origin));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidOrigins(): iterable
    {
        yield 'unencrypted' => ['http://publisher.example.org'];
        yield 'wildcard host' => ['https://*.publisher.example.org'];
        yield 'path' => ['https://publisher.example.org/player'];
        yield 'root slash' => ['https://publisher.example.org/'];
        yield 'query' => ['https://publisher.example.org?share=1'];
        yield 'fragment' => ['https://publisher.example.org#player'];
        yield 'userinfo' => ['https://user@publisher.example.org'];
        yield 'ip address' => ['https://192.0.2.10'];
        yield 'localhost' => ['https://localhost'];
        yield 'empty port' => ['https://publisher.example.org:0'];
        yield 'overlong port' => ['https://publisher.example.org:65536'];
        yield 'newline injection' => ["https://publisher.example.org\nContent-Security-Policy: *"];
        yield 'unicode hostname' => ['https://bücher.example.org'];
    }

    public function testRejectsInvalidUtf8AndTooManyOrigins(): void
    {
        $policy = new VideoEmbedOriginPolicy();

        self::assertNull($policy->parseInput("https://publisher.example.org\n\xFF"));
        self::assertNull($policy->parseInput(implode("\n", array_map(
            static fn (int $number): string => 'https://publisher'.$number.'.example.org',
            range(1, 21),
        ))));
    }

    public function testStoredOriginsMustAlreadyBeCanonicalAndWithinBounds(): void
    {
        $policy = new VideoEmbedOriginPolicy();

        self::assertSame(['https://publisher.example.org'], $policy->validateStoredOrigins(['https://publisher.example.org']));
        self::assertNull($policy->validateStoredOrigins(['HTTPS://publisher.example.org']));
        self::assertNull($policy->validateStoredOrigins(['https://publisher.example.org/path']));
        self::assertNull($policy->validateStoredOrigins(['https://publisher.example.org', 7]));
        self::assertNull($policy->validateStoredOrigins(['unexpected' => 'https://publisher.example.org']));
        self::assertNull($policy->validateStoredOrigins(array_fill(0, 21, 'https://publisher.example.org')));
    }
}
