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
    private const array DAILYMOTION_SEARCH_FIELDS = [
        'id', 'title', 'description', 'url', 'allow_embed', 'thumbnail_url', 'thumbnail_120_url',
        'thumbnail_180_url', 'thumbnail_240_url', 'thumbnail_360_url', 'thumbnail_480_url',
        'thumbnail_720_url', 'thumbnail_1080_url',
    ];
    private const array DAILYMOTION_CHANNEL_FIELDS = [
        'video_id', 'title', 'description', 'video_url', 'visibility', 'embedding.enable_embed',
        'thumbnail_url', 'thumbnail_120_url', 'thumbnail_180_url', 'thumbnail_240_url',
        'thumbnail_360_url', 'thumbnail_480_url', 'thumbnail_720_url', 'thumbnail_1080_url',
    ];
    private const array VIMEO_VIDEO_FIELDS = ['uri', 'name', 'description', 'link', 'privacy.view', 'pictures.sizes'];

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
            self::assertSame(5.0, $options['timeout'] ?? null);
            self::assertSame(10.0, $options['max_duration'] ?? null);

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
                        'thumbnails' => [
                            'medium' => ['url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/mqdefault.jpg', 'width' => 320, 'height' => 180],
                            'high' => ['url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg', 'width' => 480, 'height' => 360],
                        ],
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
            'thumbnail_url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
        ]], $items);
    }

    public function testMetadataLookupUsesFixedApiAndValidatesEmbeddabilityBeforeReturningFields(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            $parts = parse_url($url);
            self::assertIsArray($parts);
            self::assertSame('www.googleapis.com', $parts['host'] ?? null);
            self::assertSame('/youtube/v3/videos', $parts['path'] ?? null);
            self::assertSame(0, $options['max_redirects'] ?? null);
            self::assertSame(5.0, $options['timeout'] ?? null);
            self::assertSame(10.0, $options['max_duration'] ?? null);
            self::assertSame([
                'part' => 'snippet,contentDetails,status',
                'id' => 'dQw4w9WgXcQ',
                'maxResults' => 1,
            ], $options['query'] ?? null);
            $headers = $options['headers'] ?? null;
            self::assertIsArray($headers);
            self::assertContains('X-Goog-Api-Key: '.self::API_KEY, array_values($headers));

            return new MockResponse(json_encode([
                'items' => [[
                    'id' => 'dQw4w9WgXcQ',
                    'snippet' => [
                        'title' => '  Example title  ',
                        'description' => "A description\x00 with safe text.",
                        'thumbnails' => [
                            'medium' => ['url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/mqdefault.jpg', 'width' => 320, 'height' => 180],
                        ],
                    ],
                    'contentDetails' => ['duration' => 'PT1H2M3S'],
                    'status' => ['privacyStatus' => 'public', 'embeddable' => true],
                ]],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });

        $service = new VideoProviderImportService($client, new ExternalVideoEmbedResolver(), self::API_KEY);

        self::assertSame([
            'provider' => 'youtube',
            'source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'title' => 'Example title',
            'description' => 'A description with safe text.',
            'duration_seconds' => 3723,
            'thumbnail_url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/mqdefault.jpg',
        ], $service->metadataForSourceUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ&feature=share'));
    }

    public function testMetadataLookupRejectsUnknownHostsBeforeAnyNetworkRequest(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new \RuntimeException('Direct URLs and unknown providers must never be fetched.');
        });
        $service = new VideoProviderImportService($client, new ExternalVideoEmbedResolver(), self::API_KEY);

        self::assertNull($service->metadataForSourceUrl('https://media.example.test/video.mp4'));
        self::assertNull($service->metadataForSourceUrl('https://www.youtube.com/watch?v=not-a-valid-id'));
    }

    public function testEmbedProvidersWithoutVerifiedMetadataApisRemainManualAndAreNeverFetched(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new \RuntimeException('Providers without a verified fixed metadata API must never be queried.');
        });
        $service = new VideoProviderImportService($client, new ExternalVideoEmbedResolver('x8lr5'), self::API_KEY);
        $sources = [
            'https://www.facebook.com/watch/?v=20531316728',
            'https://drive.google.com/file/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/view',
            'https://rutube.ru/video/852e974534e3527f16810a7a19c418b0',
            'https://vkvideo.ru/video-123456_456239017',
            'https://v.youku.com/v_show/id_XNDI0MDAwMDAwMA==.html',
        ];

        foreach ($sources as $source) {
            self::assertNull($service->metadataForSourceUrl($source), $source);
            self::assertNull($service->checkSourceAvailability($source), $source);
        }

        self::assertSame(0, $client->getRequestsCount());
    }

    public function testMetadataLookupPreservesVimeoPrivacyHashAndRequiresPublicDailymotionEmbeds(): void
    {
        $vimeo = new VideoProviderImportService(
            new MockHttpClient([new MockResponse(json_encode([
                'uri' => '/videos/123456789',
                'name' => 'Vimeo metadata',
                'description' => 'Private-link video.',
                'link' => 'https://vimeo.com/123456789?h=privacy789',
                'duration' => 125,
                'privacy' => ['view' => 'unlisted'],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200])]),
            new ExternalVideoEmbedResolver(),
            self::API_KEY,
            'vimeo-test-access-token_123',
        );
        self::assertSame([
            'provider' => 'vimeo',
            'source_url' => 'https://vimeo.com/123456789?h=privacy789',
            'title' => 'Vimeo metadata',
            'description' => 'Private-link video.',
            'duration_seconds' => 125,
        ], $vimeo->metadataForSourceUrl('https://vimeo.com/123456789?h=privacy789'));

        $dailymotionResponse = [
            'id' => 'x84sh87',
            'title' => 'Dailymotion metadata',
            'description' => 'Public Dailymotion video.',
            'duration' => 99,
            'allow_embed' => true,
            'status' => 'published',
            'url' => 'https://www.dailymotion.com/video/x84sh87',
        ];
        $dailymotion = new VideoProviderImportService(
            new MockHttpClient([
                new MockResponse(json_encode($dailymotionResponse, JSON_THROW_ON_ERROR), ['http_code' => 200]),
                new MockResponse(json_encode(array_replace($dailymotionResponse, ['allow_embed' => false]), JSON_THROW_ON_ERROR), ['http_code' => 200]),
            ]),
            new ExternalVideoEmbedResolver('x8lr5'),
            self::API_KEY,
        );
        self::assertSame([
            'provider' => 'dailymotion',
            'source_url' => 'https://www.dailymotion.com/video/x84sh87',
            'title' => 'Dailymotion metadata',
            'description' => 'Public Dailymotion video.',
            'duration_seconds' => 99,
        ], $dailymotion->metadataForSourceUrl('https://www.dailymotion.com/video/x84sh87'));
        self::assertNull($dailymotion->metadataForSourceUrl('https://www.dailymotion.com/video/x84sh87'));
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
            ['dailymotion', '{"list":[],"list":[]}'],
        ];

        foreach ($responses as [$provider, $body]) {
            $service = new VideoProviderImportService(
                new MockHttpClient([new MockResponse($body, ['http_code' => 200])]),
                new ExternalVideoEmbedResolver('dailymotion' === $provider ? 'x8lr5' : ''),
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
                        'pictures' => ['sizes' => [
                            ['width' => 640, 'link' => 'https://i.vimeocdn.com/video/123456789_640x360.jpg'],
                            ['width' => 1280, 'link' => 'https://i.vimeocdn.com/video/123456789_1280x720.jpg'],
                        ]],
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
            'thumbnail_url' => 'https://i.vimeocdn.com/video/123456789_640x360.jpg',
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

    public function testDailymotionSearchUsesFixedEndpointAndImportsOnlyCanonicalEmbeddableVideos(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            $urlParts = parse_url($url);
            self::assertIsArray($urlParts);
            self::assertSame('https', $urlParts['scheme'] ?? null);
            self::assertSame('api.dailymotion.com', $urlParts['host'] ?? null);
            self::assertSame('/videos', $urlParts['path'] ?? null);
            self::assertSame(0, $options['max_redirects'] ?? null);
            $query = $options['query'] ?? null;
            self::assertIsArray($query);
            self::assertSame('gaming news', $query['search'] ?? null);
            self::assertSame(25, $query['limit'] ?? null);
            self::assertSame(implode(',', self::DAILYMOTION_SEARCH_FIELDS), $query['fields'] ?? null);
            self::assertStringNotContainsString('client_secret', $url);

            return new MockResponse(json_encode([
                'list' => [
                    [
                        'id' => 'x84sh87',
                        'title' => ' Dailymotion example ',
                        'description' => 'Public and embeddable.',
                        'url' => 'https://www.dailymotion.com/video/x84sh87',
                        'allow_embed' => true,
                    ],
                    [
                        'id' => 'x84sh88',
                        'title' => 'Not embeddable',
                        'description' => '',
                        'url' => 'https://www.dailymotion.com/video/x84sh88',
                        'allow_embed' => false,
                    ],
                    [
                        'id' => 'x84sh87',
                        'title' => 'Duplicate',
                        'description' => '',
                        'url' => 'https://www.dailymotion.com/video/x84sh87',
                        'allow_embed' => true,
                    ],
                ],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });
        $service = new VideoProviderImportService($client, new ExternalVideoEmbedResolver('x8lr5'), self::API_KEY);

        self::assertTrue($service->isConfigured('dailymotion'));
        self::assertSame([[
            'title' => 'Dailymotion example',
            'description' => 'Public and embeddable.',
            'source_url' => 'https://www.dailymotion.com/video/x84sh87',
        ]], $service->search('dailymotion', ' gaming news '));
    }

    public function testDailymotionChannelUsesScopedBearerTokenAndImportsOnlyPublicEmbeddableVideos(): void
    {
        $token = 'dailymotion-test-access-token';
        $clientId = 'dailymotion-private-client-id';
        $clientSecret = 'dailymotion-private-client-secret';
        $requestIndex = 0;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($token, $clientId, $clientSecret, &$requestIndex): MockResponse {
            ++$requestIndex;
            self::assertSame(0, $options['max_redirects'] ?? null);
            $urlParts = parse_url($url);
            self::assertIsArray($urlParts);
            self::assertSame('https', $urlParts['scheme'] ?? null);

            if (1 === $requestIndex) {
                self::assertSame('POST', $method);
                self::assertSame('oauth2.dailymotion.com', $urlParts['host'] ?? null);
                self::assertSame('/v2/token', $urlParts['path'] ?? null);
                self::assertStringNotContainsString($clientSecret, $url);
                self::assertStringNotContainsString($clientId, $url);
                $headers = $options['headers'] ?? null;
                self::assertIsArray($headers);
                self::assertContains('Content-Type: application/x-www-form-urlencoded', $headers);
                $body = $options['body'] ?? null;
                if (is_string($body)) {
                    parse_str($body, $form);
                } elseif (is_array($body)) {
                    $form = $body;
                } else {
                    self::fail('The Dailymotion token request must use a form-encoded body.');
                }
                self::assertSame('client_credentials', $form['grant_type'] ?? null);
                self::assertSame($clientId, $form['client_id'] ?? null);
                self::assertSame($clientSecret, $form['client_secret'] ?? null);
                self::assertSame('video.read', $form['scope'] ?? null);

                return new MockResponse(json_encode([
                    'access_token' => $token,
                    'token_type' => 'Bearer',
                    'expires_in' => 1800,
                    'scope' => 'video.read',
                ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
            }

            self::assertSame(2, $requestIndex);
            self::assertSame('GET', $method);
            self::assertSame('api.dailymotion.com', $urlParts['host'] ?? null);
            self::assertSame('/v2/profiles/profile-123/videos', $urlParts['path'] ?? null);
            self::assertStringNotContainsString($token, $url);
            $headers = $options['headers'] ?? null;
            self::assertIsArray($headers);
            self::assertContains('Authorization: Bearer '.$token, $headers);
            $query = $options['query'] ?? null;
            self::assertIsArray($query);
            self::assertSame(1, $query['page'] ?? null);
            self::assertSame(25, $query['page_size'] ?? null);
            self::assertSame(implode(',', self::DAILYMOTION_CHANNEL_FIELDS), $query['fields'] ?? null);

            return new MockResponse(json_encode([
                'data' => [
                    [
                        'video_id' => 'x84sh87',
                        'video_url' => 'https://www.dailymotion.com/video/x84sh87',
                        'title' => 'Profile video',
                        'description' => 'Public profile video.',
                        'visibility' => 'public',
                        'embedding' => ['enable_embed' => true],
                    ],
                    [
                        'video_id' => 'kprivate1',
                        'video_url' => 'https://www.dailymotion.com/video/kprivate1',
                        'title' => 'Private profile video',
                        'description' => '',
                        'visibility' => 'private',
                        'embedding' => ['enable_embed' => true],
                    ],
                    [
                        'video_id' => 'x84sh88',
                        'video_url' => 'https://www.dailymotion.com/video/x84sh88',
                        'title' => 'Non-embeddable profile video',
                        'description' => '',
                        'visibility' => 'public',
                        'embedding' => ['enable_embed' => false],
                    ],
                ],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });
        $service = new VideoProviderImportService(
            $client,
            new ExternalVideoEmbedResolver('x8lr5'),
            self::API_KEY,
            '',
            $clientId,
            $clientSecret,
        );

        self::assertTrue($service->isConfiguredForMode('dailymotion', 'channel'));
        self::assertSame([[
            'title' => 'Profile video',
            'description' => 'Public profile video.',
            'source_url' => 'https://www.dailymotion.com/video/x84sh87',
        ]], $service->search('dailymotion', '', 'profile-123'));
        self::assertSame(2, $requestIndex);
    }

    public function testDailymotionChannelRejectsMissingCredentialsAndInvalidTokenScopeBeforeVideoRequest(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new \RuntimeException('No request is allowed without Dailymotion channel credentials.');
        });
        $unconfigured = new VideoProviderImportService($client, new ExternalVideoEmbedResolver('x8lr5'), self::API_KEY);
        self::assertFalse($unconfigured->isConfiguredForMode('dailymotion', 'channel'));
        $this->assertVideoProviderException(
            static fn (): array => $unconfigured->search('dailymotion', '', 'profile-123'),
            'A Dailymotion channel import must fail before contacting the provider without credentials.',
        );

        $service = new VideoProviderImportService(
            new MockHttpClient([new MockResponse(json_encode([
                'access_token' => 'unexpected-token',
                'token_type' => 'Bearer',
                'expires_in' => 1800,
                'scope' => 'profile.read',
            ], JSON_THROW_ON_ERROR), ['http_code' => 200])]),
            new ExternalVideoEmbedResolver('x8lr5'),
            self::API_KEY,
            '',
            'dailymotion-client-id',
            'dailymotion-client-secret',
        );
        try {
            $service->search('dailymotion', '', 'profile-123');
            self::fail('A token without video.read scope must be rejected.');
        } catch (VideoProviderImportException $exception) {
            self::assertStringNotContainsString('unexpected-token', $exception->getMessage());
            self::assertStringNotContainsString('dailymotion-client-secret', $exception->getMessage());
        }
    }

    public function testDailymotionChannelIdIsRejectedBeforeProviderRequest(): void
    {
        $noRequestClient = new MockHttpClient(static function (): MockResponse {
            throw new \RuntimeException('No provider request is allowed for an unsafe Dailymotion profile ID.');
        });
        $configuredService = new VideoProviderImportService(
            $noRequestClient,
            new ExternalVideoEmbedResolver('x8lr5'),
            self::API_KEY,
            '',
            'dailymotion-client-id',
            'dailymotion-client-secret',
        );
        self::assertFalse(VideoProviderImportService::isValidChannelId('dailymotion', 'https://attacker.example'));
        $this->expectException(\InvalidArgumentException::class);
        $configuredService->search('dailymotion', '', 'https://attacker.example');
    }

    public function testMalformedDailymotionSearchResponseIsRejected(): void
    {
        $malformedService = new VideoProviderImportService(
            new MockHttpClient([new MockResponse('{"list":{}}', ['http_code' => 200])]),
            new ExternalVideoEmbedResolver('x8lr5'),
            self::API_KEY,
        );
        try {
            $malformedService->search('dailymotion', 'nature');
            self::fail('A malformed Dailymotion result list must fail instead of returning an empty successful import.');
        } catch (VideoProviderImportException $exception) {
            self::assertSame('Der Videoanbieter hat eine ungültige Ergebnisliste geliefert.', $exception->getMessage());
        }
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

    public function testYouTubePlaylistVerifiesPrivacyAndEmbedEligibilityBeforeReturningVideos(): void
    {
        $requestIndex = 0;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requestIndex): MockResponse {
            ++$requestIndex;
            self::assertSame('GET', $method);
            self::assertSame(0, $options['max_redirects'] ?? null);
            $parts = parse_url($url);
            self::assertIsArray($parts);
            self::assertSame('www.googleapis.com', $parts['host'] ?? null);
            self::assertSame('https', $parts['scheme'] ?? null);

            $headers = $options['headers'] ?? null;
            self::assertIsArray($headers);
            self::assertContains('X-Goog-Api-Key: '.self::API_KEY, $headers);
            $query = $options['query'] ?? null;
            self::assertIsArray($query);

            if (1 === $requestIndex) {
                self::assertSame('/youtube/v3/playlistItems', $parts['path'] ?? null);
                self::assertSame('contentDetails', $query['part'] ?? null);
                self::assertSame(25, $query['maxResults'] ?? null);
                self::assertSame('PL123456789ABC', $query['playlistId'] ?? null);

                return new MockResponse(json_encode([
                    'items' => [
                        ['contentDetails' => ['videoId' => 'dQw4w9WgXcQ']],
                        ['contentDetails' => ['videoId' => 'abcdefghijk']],
                        ['contentDetails' => ['videoId' => 'dQw4w9WgXcQ']],
                        ['contentDetails' => ['videoId' => null]],
                    ],
                ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
            }

            self::assertSame(2, $requestIndex);
            self::assertSame('/youtube/v3/videos', $parts['path'] ?? null);
            self::assertSame('snippet,status', $query['part'] ?? null);
            self::assertSame('dQw4w9WgXcQ,abcdefghijk', $query['id'] ?? null);

            return new MockResponse(json_encode([
                'items' => [
                    [
                        'id' => 'dQw4w9WgXcQ',
                        'snippet' => ['title' => 'Public video', 'description' => 'Public description.'],
                        'status' => ['privacyStatus' => 'public', 'embeddable' => true],
                    ],
                    [
                        'id' => 'abcdefghijk',
                        'snippet' => ['title' => 'Private video', 'description' => ''],
                        'status' => ['privacyStatus' => 'private', 'embeddable' => true],
                    ],
                ],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });
        $service = new VideoProviderImportService($client, new ExternalVideoEmbedResolver(), self::API_KEY);

        self::assertTrue($service->isConfiguredForMode('youtube', 'playlist'));
        self::assertSame([[
            'title' => 'Public video',
            'description' => 'Public description.',
            'source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ]], $service->playlist('youtube', 'PL123456789ABC'));
        self::assertSame(2, $requestIndex);
    }

    public function testVimeoPlaylistUsesAuthenticatedShowcaseAndImportsOnlyPublicVideos(): void
    {
        $token = 'vimeo-test-access-token_123';
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($token): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame(0, $options['max_redirects'] ?? null);
            $parts = parse_url($url);
            self::assertIsArray($parts);
            self::assertSame('api.vimeo.com', $parts['host'] ?? null);
            self::assertSame('/me/albums/123456789/videos', $parts['path'] ?? null);
            self::assertStringNotContainsString($token, $url);
            $headers = $options['headers'] ?? null;
            self::assertIsArray($headers);
            self::assertContains('Authorization: Bearer '.$token, $headers);
            $query = $options['query'] ?? null;
            self::assertIsArray($query);
            self::assertSame(25, $query['per_page'] ?? null);
            self::assertSame('embeddable', $query['filter'] ?? null);
            self::assertSame(implode(',', self::VIMEO_VIDEO_FIELDS), $query['fields'] ?? null);

            return new MockResponse(json_encode([
                'data' => [
                    [
                        'uri' => '/videos/123456789',
                        'name' => 'Public Vimeo video',
                        'description' => 'Public showcase video.',
                        'link' => 'https://vimeo.com/123456789',
                        'privacy' => ['view' => 'anybody'],
                    ],
                    [
                        'uri' => '/videos/987654321',
                        'name' => 'Private Vimeo video',
                        'description' => '',
                        'link' => 'https://vimeo.com/987654321',
                        'privacy' => ['view' => 'unlisted'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });
        $service = new VideoProviderImportService(
            $client,
            new ExternalVideoEmbedResolver(),
            self::API_KEY,
            $token,
        );

        self::assertSame([[
            'title' => 'Public Vimeo video',
            'description' => 'Public showcase video.',
            'source_url' => 'https://vimeo.com/123456789',
        ]], $service->playlist('vimeo', '123456789'));
    }

    public function testDailymotionPlaylistRequiresScopedCredentialsAndFiltersPrivateVideos(): void
    {
        $token = 'dailymotion-playlist-test-token';
        $clientId = 'dailymotion-test-client-id';
        $clientSecret = 'dailymotion-test-client-secret';
        $requestIndex = 0;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($token, $clientId, $clientSecret, &$requestIndex): MockResponse {
            ++$requestIndex;
            $parts = parse_url($url);
            self::assertIsArray($parts);
            self::assertSame('https', $parts['scheme'] ?? null);
            self::assertSame(0, $options['max_redirects'] ?? null);

            if (1 === $requestIndex) {
                self::assertSame('POST', $method);
                self::assertSame('oauth2.dailymotion.com', $parts['host'] ?? null);
                self::assertSame('/v2/token', $parts['path'] ?? null);
                self::assertStringNotContainsString($clientId, $url);
                self::assertStringNotContainsString($clientSecret, $url);
                $body = $options['body'] ?? null;
                if (is_string($body)) {
                    parse_str($body, $form);
                } elseif (is_array($body)) {
                    $form = $body;
                } else {
                    self::fail('The Dailymotion token request must use a form-encoded body.');
                }
                self::assertSame($clientId, $form['client_id'] ?? null);
                self::assertSame($clientSecret, $form['client_secret'] ?? null);
                self::assertSame('playlist.read', $form['scope'] ?? null);

                return new MockResponse(json_encode([
                    'access_token' => $token,
                    'token_type' => 'Bearer',
                    'expires_in' => 1200,
                    'scope' => 'playlist.read',
                ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
            }

            self::assertSame(2, $requestIndex);
            self::assertSame('GET', $method);
            self::assertSame('api.dailymotion.com', $parts['host'] ?? null);
            self::assertSame('/v2/playlists/playlist-123/videos', $parts['path'] ?? null);
            self::assertStringNotContainsString($token, $url);
            $headers = $options['headers'] ?? null;
            self::assertIsArray($headers);
            self::assertContains('Authorization: Bearer '.$token, $headers);
            $query = $options['query'] ?? null;
            self::assertIsArray($query);
            self::assertSame(1, $query['page'] ?? null);
            self::assertSame(25, $query['page_size'] ?? null);
            self::assertSame(implode(',', self::DAILYMOTION_CHANNEL_FIELDS), $query['fields'] ?? null);

            return new MockResponse(json_encode([
                'data' => [
                    [
                        'video_id' => 'x84sh87',
                        'video_url' => 'https://www.dailymotion.com/video/x84sh87',
                        'title' => 'Public Dailymotion video',
                        'description' => 'Public playlist video.',
                        'visibility' => 'public',
                        'embedding' => ['enable_embed' => true],
                    ],
                    [
                        'video_id' => 'x84sh88',
                        'video_url' => 'https://www.dailymotion.com/video/x84sh88',
                        'title' => 'Private Dailymotion video',
                        'description' => '',
                        'visibility' => 'private',
                        'embedding' => ['enable_embed' => true],
                    ],
                ],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });
        $service = new VideoProviderImportService(
            $client,
            new ExternalVideoEmbedResolver('x8lr5'),
            self::API_KEY,
            '',
            $clientId,
            $clientSecret,
        );

        self::assertTrue($service->isConfiguredForMode('dailymotion', 'playlist'));
        self::assertSame([[
            'title' => 'Public Dailymotion video',
            'description' => 'Public playlist video.',
            'source_url' => 'https://www.dailymotion.com/video/x84sh87',
        ]], $service->playlist('dailymotion', 'playlist-123'));
        self::assertSame(2, $requestIndex);
    }

    public function testYouTubeFavoritesUseRefreshTokenAndExcludePrivateOrNonEmbeddableVideos(): void
    {
        $requestIndex = 0;
        $clientId = 'youtube-oauth-client-id';
        $clientSecret = 'youtube-oauth-client-secret';
        $refreshToken = 'youtube-oauth-refresh-token';
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (
            &$requestIndex,
            $clientId,
            $clientSecret,
            $refreshToken,
        ): MockResponse {
            ++$requestIndex;
            self::assertSame(0, $options['max_redirects'] ?? null);
            $parts = parse_url($url);
            self::assertIsArray($parts);
            self::assertSame('https', $parts['scheme'] ?? null);
            self::assertStringNotContainsString($clientSecret, $url);
            self::assertStringNotContainsString($refreshToken, $url);

            if (1 === $requestIndex) {
                self::assertSame('POST', $method);
                self::assertSame('oauth2.googleapis.com', $parts['host'] ?? null);
                self::assertSame('/token', $parts['path'] ?? null);
                $body = $options['body'] ?? null;
                $headers = $options['headers'] ?? null;
                self::assertIsArray($headers);
                self::assertContains('Content-Type: application/x-www-form-urlencoded', $headers);
                if (is_string($body)) {
                    parse_str($body, $form);
                } elseif (is_array($body)) {
                    $form = $body;
                } else {
                    self::fail('The YouTube token request must use a form-encoded body.');
                }
                self::assertSame('refresh_token', $form['grant_type'] ?? null);
                self::assertSame($clientId, $form['client_id'] ?? null);
                self::assertSame($clientSecret, $form['client_secret'] ?? null);
                self::assertSame($refreshToken, $form['refresh_token'] ?? null);

                return new MockResponse(json_encode([
                    'access_token' => 'youtube-short-lived-access-token',
                    'token_type' => 'Bearer',
                    'expires_in' => 3599,
                ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
            }

            self::assertSame(2, $requestIndex);
            self::assertSame('GET', $method);
            self::assertSame('www.googleapis.com', $parts['host'] ?? null);
            self::assertSame('/youtube/v3/videos', $parts['path'] ?? null);
            $headers = $options['headers'] ?? null;
            self::assertIsArray($headers);
            self::assertContains('Authorization: Bearer youtube-short-lived-access-token', $headers);
            self::assertStringNotContainsString('youtube-short-lived-access-token', $url);
            $query = $options['query'] ?? null;
            self::assertIsArray($query);
            self::assertSame('snippet,status', $query['part'] ?? null);
            self::assertSame('like', $query['myRating'] ?? null);
            self::assertSame(25, $query['maxResults'] ?? null);

            return new MockResponse(json_encode([
                'items' => [
                    [
                        'id' => 'dQw4w9WgXcQ',
                        'snippet' => ['title' => 'Liked public video', 'description' => 'Public description.'],
                        'status' => ['privacyStatus' => 'public', 'embeddable' => true],
                    ],
                    [
                        'id' => 'abcdefghijk',
                        'snippet' => ['title' => 'Private liked video', 'description' => ''],
                        'status' => ['privacyStatus' => 'private', 'embeddable' => true],
                    ],
                    [
                        'id' => '12345678901',
                        'snippet' => ['title' => 'Blocked liked video', 'description' => ''],
                        'status' => ['privacyStatus' => 'public', 'embeddable' => false],
                    ],
                ],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });
        $service = new VideoProviderImportService(
            $client,
            new ExternalVideoEmbedResolver(),
            '',
            '',
            '',
            '',
            $clientId,
            $clientSecret,
            $refreshToken,
        );

        self::assertTrue($service->isConfiguredForMode('youtube', 'favorites'));
        self::assertSame([[
            'title' => 'Liked public video',
            'description' => 'Public description.',
            'source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ]], $service->favorites('youtube'));
        self::assertSame(2, $requestIndex);
    }

    public function testVimeoFavoritesUseTheAuthenticatedLikesConnectionAndFilterPrivateVideos(): void
    {
        $token = 'vimeo-favorites-access-token';
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($token): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame(0, $options['max_redirects'] ?? null);
            $parts = parse_url($url);
            self::assertIsArray($parts);
            self::assertSame('https', $parts['scheme'] ?? null);
            self::assertSame('api.vimeo.com', $parts['host'] ?? null);
            self::assertSame('/me/likes', $parts['path'] ?? null);
            self::assertStringNotContainsString($token, $url);
            $headers = $options['headers'] ?? null;
            self::assertIsArray($headers);
            self::assertContains('Authorization: Bearer '.$token, $headers);
            $query = $options['query'] ?? null;
            self::assertIsArray($query);
            self::assertSame('embeddable', $query['filter'] ?? null);
            self::assertSame('true', $query['filter_embeddable'] ?? null);
            self::assertSame(25, $query['per_page'] ?? null);

            return new MockResponse(json_encode([
                'data' => [
                    [
                        'uri' => '/videos/123456789',
                        'name' => 'Liked public Vimeo video',
                        'description' => 'Public description.',
                        'link' => 'https://vimeo.com/123456789',
                        'privacy' => ['view' => 'anybody'],
                    ],
                    [
                        'uri' => '/videos/234567890',
                        'name' => 'Private liked Vimeo video',
                        'description' => '',
                        'link' => 'https://vimeo.com/234567890',
                        'privacy' => ['view' => 'nobody'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });
        $service = new VideoProviderImportService($client, new ExternalVideoEmbedResolver(), '', $token);

        self::assertTrue($service->isConfiguredForMode('vimeo', 'favorites'));
        self::assertSame([[
            'title' => 'Liked public Vimeo video',
            'description' => 'Public description.',
            'source_url' => 'https://vimeo.com/123456789',
        ]], $service->favorites('vimeo'));
    }

    public function testDailymotionFavoritesUseAValidatedProfileAndOnlyEmbeddableEntries(): void
    {
        $requestCount = 0;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requestCount): MockResponse {
            ++$requestCount;
            self::assertSame('GET', $method);
            self::assertSame(0, $options['max_redirects'] ?? null);
            $parts = parse_url($url);
            self::assertIsArray($parts);
            self::assertSame('https', $parts['scheme'] ?? null);
            self::assertSame('api.dailymotion.com', $parts['host'] ?? null);
            self::assertSame('/user/x1favorites/favorites', $parts['path'] ?? null);
            $query = $options['query'] ?? null;
            self::assertIsArray($query);
            self::assertSame(25, $query['limit'] ?? null);
            self::assertSame(implode(',', self::DAILYMOTION_SEARCH_FIELDS), $query['fields'] ?? null);

            return new MockResponse(json_encode([
                'list' => [
                    [
                        'id' => 'x84sh87',
                        'title' => 'Public Dailymotion favorite',
                        'description' => 'Embeddable favorite.',
                        'url' => 'https://www.dailymotion.com/video/x84sh87',
                        'allow_embed' => true,
                    ],
                    [
                        'id' => 'x84sh88',
                        'title' => 'Blocked Dailymotion favorite',
                        'description' => '',
                        'url' => 'https://www.dailymotion.com/video/x84sh88',
                        'allow_embed' => false,
                    ],
                ],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });
        $service = new VideoProviderImportService($client, new ExternalVideoEmbedResolver('x8lr5'), '');

        self::assertTrue($service->isConfiguredForMode('dailymotion', 'favorites'));
        self::assertSame([[
            'title' => 'Public Dailymotion favorite',
            'description' => 'Embeddable favorite.',
            'source_url' => 'https://www.dailymotion.com/video/x84sh87',
        ]], $service->favorites('dailymotion', 'x1favorites'));
        self::assertSame(1, $requestCount);
    }

    public function testFavoritesRejectMissingYouTubeAuthorizationAndUnsafeDailymotionProfileBeforeRequests(): void
    {
        $noRequestClient = new MockHttpClient(static function (): MockResponse {
            self::fail('Favorite import must not contact a provider without the required authorization or a valid profile ID.');
        });
        $service = new VideoProviderImportService($noRequestClient, new ExternalVideoEmbedResolver('x8lr5'), self::API_KEY);

        self::assertFalse($service->isConfiguredForMode('youtube', 'favorites'));
        $this->assertVideoProviderException(
            static fn (): array => $service->favorites('youtube'),
            'YouTube favorites must fail before a provider request without OAuth credentials.',
            'Der YouTube-Favoritenimport ist nicht konfiguriert.',
        );

        try {
            $service->favorites('dailymotion', 'https://attacker.example');
            self::fail('A Dailymotion favorites URL must be rejected as an invalid profile ID.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Die Dailymotion-Profil-ID ist ungültig.', $exception->getMessage());
        }
    }

    public function testPlaylistIdentifiersAndCredentialsFailClosedBeforeProviderRequests(): void
    {
        foreach ([
            ['youtube', 'https://youtube.com/playlist?list=PL123456789ABC'],
            ['vimeo', '0'],
            ['dailymotion', '../private'],
        ] as [$provider, $playlistId]) {
            self::assertFalse(VideoProviderImportService::isValidPlaylistId($provider, $playlistId));
        }

        $noRequestClient = new MockHttpClient(static function (): MockResponse {
            throw new \RuntimeException('No provider request is allowed for an invalid or unconfigured playlist import.');
        });
        $service = new VideoProviderImportService($noRequestClient, new ExternalVideoEmbedResolver('x8lr5'), self::API_KEY);
        self::assertFalse($service->isConfiguredForMode('dailymotion', 'playlist'));
        $this->assertVideoProviderException(
            static fn (): array => $service->playlist('dailymotion', 'playlist-123'),
            'A Dailymotion playlist import without server credentials must fail before contacting the provider.',
        );
        $this->expectException(\InvalidArgumentException::class);
        $service->playlist('dailymotion', 'https://attacker.example');
    }

    public function testDailymotionPlaylistRejectsTokenWithoutPlaylistReadScope(): void
    {
        $service = new VideoProviderImportService(
            new MockHttpClient([new MockResponse(json_encode([
                'access_token' => 'dailymotion-test-token',
                'token_type' => 'Bearer',
                'expires_in' => 1200,
                'scope' => 'video.read',
            ], JSON_THROW_ON_ERROR), ['http_code' => 200])]),
            new ExternalVideoEmbedResolver('x8lr5'),
            self::API_KEY,
            '',
            'dailymotion-test-client-id',
            'dailymotion-test-client-secret',
        );

        $this->assertVideoProviderException(
            static fn (): array => $service->playlist('dailymotion', 'playlist-123'),
            'A Dailymotion token without playlist.read must be rejected before requesting playlist videos.',
            'Dailymotion hat keine gültige Leseberechtigung bereitgestellt.',
        );
    }

    public function testPlaylistRejectsMalformedAndMismatchedProviderResponses(): void
    {
        $malformed = new VideoProviderImportService(
            new MockHttpClient([new MockResponse('{"items":{}}', ['http_code' => 200])]),
            new ExternalVideoEmbedResolver(),
            self::API_KEY,
        );
        $this->assertVideoProviderException(
            static fn (): array => $malformed->playlist('youtube', 'PL123456789ABC'),
            'A malformed playlist response must not count as an empty successful import.',
            'Der Videoanbieter hat eine ungültige Ergebnisliste geliefert.',
        );

        $mismatched = new VideoProviderImportService(
            new MockHttpClient([
                new MockResponse(json_encode(['items' => [['contentDetails' => ['videoId' => 'dQw4w9WgXcQ']]]], JSON_THROW_ON_ERROR), ['http_code' => 200]),
                new MockResponse(json_encode(['items' => [[
                    'id' => 'abcdefghijk',
                    'snippet' => ['title' => 'Unexpected video', 'description' => ''],
                    'status' => ['privacyStatus' => 'public', 'embeddable' => true],
                ]]], JSON_THROW_ON_ERROR), ['http_code' => 200]),
            ]),
            new ExternalVideoEmbedResolver(),
            self::API_KEY,
        );
        $this->assertVideoProviderException(
            static fn (): array => $mismatched->playlist('youtube', 'PL123456789ABC'),
            'A video absent from the requested playlist must be rejected.',
            'Der Videoanbieter hat ein ungültiges Video geliefert.',
        );

        $mismatchedDailymotion = new VideoProviderImportService(
            new MockHttpClient([
                new MockResponse(json_encode([
                    'access_token' => 'dailymotion-test-token',
                    'token_type' => 'Bearer',
                    'expires_in' => 1200,
                    'scope' => 'playlist.read',
                ], JSON_THROW_ON_ERROR), ['http_code' => 200]),
                new MockResponse(json_encode(['data' => [[
                    'video_id' => 'x84sh87',
                    'video_url' => 'https://www.dailymotion.com/video/x84sh88',
                    'title' => 'Mismatched video identity',
                    'description' => '',
                    'visibility' => 'public',
                    'embedding' => ['enable_embed' => true],
                ]]], JSON_THROW_ON_ERROR), ['http_code' => 200]),
            ]),
            new ExternalVideoEmbedResolver('x8lr5'),
            self::API_KEY,
            '',
            'dailymotion-test-client-id',
            'dailymotion-test-client-secret',
        );
        $this->assertVideoProviderException(
            static fn (): array => $mismatchedDailymotion->playlist('dailymotion', 'playlist-123'),
            'A Dailymotion playlist row with a mismatched video ID and URL must be rejected.',
            'Der Videoanbieter hat ein ungültiges Video geliefert.',
        );
    }

    /** @param callable(): list<array{title: string, description: string, source_url: string}> $operation */
    private function assertVideoProviderException(callable $operation, string $failureMessage, ?string $expectedMessage = null): void
    {
        try {
            $operation();
        } catch (VideoProviderImportException $exception) {
            self::assertNotSame('', $exception->getMessage());
            if (null !== $expectedMessage) {
                self::assertSame($expectedMessage, $exception->getMessage());
            }

            return;
        }

        self::fail($failureMessage);
    }
}
