<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ExternalVideoEmbedResolver;
use App\Service\VideoProviderImportException;
use App\Service\VideoProviderImportService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class VideoProviderImportServiceTest extends TestCase
{
    private const string API_KEY = 'test-provider-api-key';

    public function testSearchUsesFixedEndpointSafeHeadersAndReturnsCanonicalVideoSources(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            $urlParts = parse_url($url);
            self::assertIsArray($urlParts);
            self::assertSame('https', $urlParts['scheme'] ?? null);
            self::assertSame('www.googleapis.com', $urlParts['host'] ?? null);
            self::assertSame('/youtube/v3/search', $urlParts['path'] ?? null);
            self::assertStringNotContainsString(self::API_KEY, $url);
            self::assertSame(0, $options['max_redirects'] ?? null);

            $headers = $options['headers'] ?? null;
            self::assertIsArray($headers);
            $apiKeyHeaders = array_values(array_filter(
                $headers,
                static fn (mixed $header): bool => is_string($header)
                    && str_starts_with(strtolower($header), 'x-goog-api-key:'),
            ));
            self::assertSame(['X-Goog-Api-Key: '.self::API_KEY], $apiKeyHeaders);

            $query = $options['query'] ?? null;
            self::assertIsArray($query);
            self::assertSame('snippet', $query['part'] ?? null);
            self::assertSame('video', $query['type'] ?? null);
            self::assertSame(25, $query['maxResults'] ?? null);
            self::assertSame('strict', $query['safeSearch'] ?? null);
            self::assertSame('true', $query['videoEmbeddable'] ?? null);
            self::assertSame('gaming news', $query['q'] ?? null);
            self::assertArrayNotHasKey('key', $query);

            return new MockResponse(json_encode([
                'items' => [[
                    'id' => ['videoId' => 'dQw4w9WgXcQ'],
                    'snippet' => [
                        'title' => '  Beispielvideo  ',
                        'description' => "Beschreibung\x00 mit Zeilenumbruch\n.",
                    ],
                ]],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });

        $service = new VideoProviderImportService($client, new ExternalVideoEmbedResolver(), self::API_KEY);
        $items = $service->search('youtube', ' gaming news ');

        self::assertSame([[
            'title' => 'Beispielvideo',
            'description' => "Beschreibung mit Zeilenumbruch\n.",
            'source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ]], $items);
    }

    public function testChannelImportUsesOnlyAValidatedChannelId(): void
    {
        $channelId = 'UC'.str_repeat('a', 22);
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($channelId): MockResponse {
            $query = $options['query'] ?? null;
            self::assertIsArray($query);
            self::assertSame($channelId, $query['channelId'] ?? null);
            self::assertArrayNotHasKey('q', $query);

            return new MockResponse('{"items":[]}', ['http_code' => 200]);
        });

        $service = new VideoProviderImportService($client, new ExternalVideoEmbedResolver(), self::API_KEY);

        self::assertSame([], $service->search('youtube', '', $channelId));
    }

    public function testInvalidChannelIdIsRejectedBeforeAnyHttpRequest(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new \RuntimeException('A request must not be sent for an invalid channel ID.');
        });
        $service = new VideoProviderImportService($client, new ExternalVideoEmbedResolver(), self::API_KEY);

        $this->expectException(\InvalidArgumentException::class);
        $service->search('youtube', '', 'https://youtube.com/@attacker');
    }

    public function testMissingApiKeyFailsClosedBeforeAnyHttpRequest(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new \RuntimeException('A request must not be sent without an API key.');
        });
        $service = new VideoProviderImportService($client, new ExternalVideoEmbedResolver(), '');

        self::assertFalse($service->isConfigured('youtube'));
        $this->expectException(VideoProviderImportException::class);
        $service->search('youtube', 'gaming');
    }

    public function testOversizedProviderResponseIsRejected(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            str_repeat('x', 1048577),
            ['http_code' => 200],
        ));
        $service = new VideoProviderImportService($client, new ExternalVideoEmbedResolver(), self::API_KEY);

        $this->expectException(VideoProviderImportException::class);
        $service->search('youtube', 'gaming');
    }

    public function testProviderResponsesWithDuplicateJsonKeysAreRejectedAtEveryObjectDepth(): void
    {
        $responses = [
            ['youtube', '{"items":[],"items":[]}'],
            ['youtube', '{"items":[{"id":{"videoId":"dQw4w9WgXcQ","videoId":"aaaaaaaaaaa"},"snippet":{"title":"Video"}}]}'],
            ['youtube', '{"items":[{"id":{"videoId":"dQw4w9WgXcQ"},"snippet":{"title":"First","\\u0074itle":"Second"}}]}'],
            ['vimeo', '{"data":[{"uri":"/videos/123456789","name":"Video","description":"","link":"https://vimeo.com/123456789","name":"Different"}]}'],
        ];

        foreach ($responses as [$provider, $body]) {
            $service = new VideoProviderImportService(
                new MockHttpClient([new MockResponse($body, ['http_code' => 200])]),
                new ExternalVideoEmbedResolver(),
                self::API_KEY,
                'vimeo-test-access-token_123',
            );

            try {
                $service->search($provider, 'gaming');
                self::fail(sprintf('The %s provider response must reject duplicate JSON object keys.', $provider));
            } catch (VideoProviderImportException $exception) {
                self::assertSame('Der Videoanbieter hat ungültige oder mehrdeutige Daten geliefert.', $exception->getMessage());
            }
        }
    }

    public function testRedirectAndInvalidProviderIdsAreRejected(): void
    {
        $redirectClient = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame(0, $options['max_redirects'] ?? null);

            return new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['Location: http://169.254.169.254/latest/meta-data/'],
            ]);
        });
        $redirectService = new VideoProviderImportService($redirectClient, new ExternalVideoEmbedResolver(), self::API_KEY);

        try {
            $redirectService->search('youtube', 'gaming');
            self::fail('Redirects must be rejected.');
        } catch (VideoProviderImportException $exception) {
            self::assertStringNotContainsString(self::API_KEY, $exception->getMessage());
        }

        $invalidPayloadClient = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            '{"items":[{"id":{"videoId":"https://evil.example"},"snippet":{"title":"Unsafe","description":""}}]}',
            ['http_code' => 200],
        ));
        $invalidPayloadService = new VideoProviderImportService($invalidPayloadClient, new ExternalVideoEmbedResolver(), self::API_KEY);

        $this->expectException(VideoProviderImportException::class);
        $invalidPayloadService->search('youtube', 'gaming');
    }

    public function testVimeoSearchUsesItsFixedApiBearerAuthenticationAndCanonicalSources(): void
    {
        $token = 'vimeo-test-access-token_123';
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($token): MockResponse {
            self::assertSame('GET', $method);
            $urlParts = parse_url($url);
            self::assertIsArray($urlParts);
            self::assertSame('https', $urlParts['scheme'] ?? null);
            self::assertSame('api.vimeo.com', $urlParts['host'] ?? null);
            self::assertSame('/videos', $urlParts['path'] ?? null);
            self::assertStringNotContainsString($token, $url);
            self::assertSame(0, $options['max_redirects'] ?? null);

            $headers = $options['headers'] ?? null;
            self::assertIsArray($headers);
            $authorizationHeaders = array_values(array_filter(
                $headers,
                static fn (mixed $header): bool => is_string($header)
                    && str_starts_with(strtolower($header), 'authorization:'),
            ));
            self::assertSame(['Authorization: Bearer '.$token], $authorizationHeaders);
            self::assertContains('Accept: application/vnd.vimeo.video+json', $headers);

            $query = $options['query'] ?? null;
            self::assertIsArray($query);
            self::assertSame(25, $query['per_page'] ?? null);
            self::assertSame('embeddable', $query['filter'] ?? null);
            self::assertArrayNotHasKey('filter_embeddable', $query);
            self::assertSame('nature films', $query['query'] ?? null);

            return new MockResponse(json_encode([
                'data' => [
                    [
                        'uri' => '/videos/123456789',
                        'name' => '  Vimeo-Beispiel  ',
                        'description' => null,
                        'link' => 'https://vimeo.com/123456789?h=abc123&share=copy',
                    ],
                    [
                        'uri' => '/videos/123456789',
                        'name' => 'Doppelter Treffer',
                        'description' => '',
                        'link' => 'https://www.vimeo.com/123456789?h=abc123',
                    ],
                ],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });
        $service = new VideoProviderImportService($client, new ExternalVideoEmbedResolver(), self::API_KEY, $token);

        self::assertTrue($service->isConfigured('vimeo'));
        self::assertSame([[
            'title' => 'Vimeo-Beispiel',
            'description' => '',
            'source_url' => 'https://vimeo.com/123456789?h=abc123',
        ]], $service->search('vimeo', ' nature films '));
    }

    public function testVimeoChannelImportUsesOnlyAValidatedChannelIdentifier(): void
    {
        $channelId = '123456789';
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($channelId): MockResponse {
            self::assertSame('GET', $method);
            $urlParts = parse_url($url);
            self::assertIsArray($urlParts);
            self::assertSame('api.vimeo.com', $urlParts['host'] ?? null);
            self::assertSame('/users/'.$channelId.'/videos', $urlParts['path'] ?? null);
            $query = $options['query'] ?? null;
            self::assertIsArray($query);
            self::assertArrayNotHasKey('query', $query);
            self::assertSame('embeddable', $query['filter'] ?? null);
            self::assertTrue($query['filter_embeddable'] ?? false);
            self::assertSame(0, $options['max_redirects'] ?? null);

            return new MockResponse('{"data":[]}', ['http_code' => 200]);
        });
        $service = new VideoProviderImportService($client, new ExternalVideoEmbedResolver(), self::API_KEY, 'vimeo-test-access-token_123');

        self::assertTrue(VideoProviderImportService::isValidChannelId('vimeo', $channelId));
        self::assertSame([], $service->search('vimeo', '', $channelId));
    }

    public function testInvalidVimeoChannelCannotChangeTheRequestHostOrPath(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new \RuntimeException('A request must not be sent for an invalid channel ID.');
        });
        $service = new VideoProviderImportService($client, new ExternalVideoEmbedResolver(), self::API_KEY, 'vimeo-test-access-token_123');

        $this->expectException(\InvalidArgumentException::class);
        $service->search('vimeo', '', '../api.vimeo.com');
    }

    public function testMissingVimeoTokenFailsClosedBeforeAnyHttpRequest(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new \RuntimeException('A request must not be sent without a Vimeo access token.');
        });
        $service = new VideoProviderImportService($client, new ExternalVideoEmbedResolver(), self::API_KEY);

        self::assertFalse($service->isConfigured('vimeo'));
        $this->expectException(VideoProviderImportException::class);
        $service->search('vimeo', 'nature');
    }

    public function testVimeoRejectsInvalidLinksMalformedResponsesAndProviderFailures(): void
    {
        $invalidLinks = [
            'https://attacker.example/123456789',
            'https://vimeo.com/987654321',
            'https://vimeo.com/123456789?h=bad&h=duplicate',
        ];

        foreach ($invalidLinks as $link) {
            $service = new VideoProviderImportService(
                new MockHttpClient([new MockResponse(json_encode([
                    'data' => [[
                        'uri' => '/videos/123456789',
                        'name' => 'Invalid source',
                        'description' => '',
                        'link' => $link,
                    ]],
                ], JSON_THROW_ON_ERROR), ['http_code' => 200])]),
                new ExternalVideoEmbedResolver(),
                self::API_KEY,
                'vimeo-test-access-token_123',
            );

            $this->assertVideoProviderException(
                static fn (): array => $service->search('vimeo', 'nature'),
                'An unapproved or mismatched Vimeo link must be rejected.',
            );
        }

        $malformedService = new VideoProviderImportService(
            new MockHttpClient([new MockResponse('{"data":{}}', ['http_code' => 200])]),
            new ExternalVideoEmbedResolver(),
            self::API_KEY,
            'vimeo-test-access-token_123',
        );
        $this->assertVideoProviderException(
            static fn (): array => $malformedService->search('vimeo', 'nature'),
            'A malformed Vimeo result list must be rejected.',
        );

        $failedProviderService = new VideoProviderImportService(
            new MockHttpClient([new MockResponse('{"error":"rate limited"}', ['http_code' => 429])]),
            new ExternalVideoEmbedResolver(),
            self::API_KEY,
            'vimeo-test-access-token_123',
        );
        try {
            $failedProviderService->search('vimeo', 'nature');
            self::fail('A provider error must not be treated as a successful import.');
        } catch (VideoProviderImportException $exception) {
            self::assertStringNotContainsString('rate limited', $exception->getMessage());
            self::assertStringNotContainsString('vimeo-test-access-token_123', $exception->getMessage());
        }
    }

    /** @param callable(): list<array{title: string, description: string, source_url: string}> $operation */
    private function assertVideoProviderException(callable $operation, string $failureMessage): void
    {
        try {
            $operation();
        } catch (VideoProviderImportException) {
            return;
        }

        self::fail($failureMessage);
    }
}
