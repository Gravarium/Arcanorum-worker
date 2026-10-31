<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ExternalVideoEmbedResolver;
use App\Service\VideoProviderImportException;
use App\Service\VideoProviderImportService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class VideoProviderSourceAvailabilityTest extends TestCase
{
    private const string API_KEY = 'source-check-test-api-key';

    public function testYoutubeAvailabilityUsesOnlyTheFixedApiAndReportsADeletedVideo(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            $parts = parse_url($url);
            self::assertIsArray($parts);
            self::assertSame('www.googleapis.com', $parts['host'] ?? null);
            self::assertSame('/youtube/v3/videos', $parts['path'] ?? null);
            $query = $options['query'] ?? null;
            self::assertIsArray($query);
            self::assertSame('dQw4w9WgXcQ', $query['id'] ?? null);
            self::assertSame('status', $query['part'] ?? null);
            self::assertSame(0, $options['max_redirects'] ?? null);
            self::assertStringNotContainsString(self::API_KEY, $url);

            return new MockResponse('{"items":[]}', ['http_code' => 200]);
        });
        $service = new VideoProviderImportService($client, new ExternalVideoEmbedResolver(), self::API_KEY);

        self::assertFalse($service->checkSourceAvailability('https://youtu.be/dQw4w9WgXcQ'));
    }

    public function testYoutubePublicEmbeddableVideoIsAvailable(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            '{"items":[{"id":"dQw4w9WgXcQ","status":{"privacyStatus":"public","embeddable":true}}]}',
            ['http_code' => 200],
        ));
        $service = new VideoProviderImportService($client, new ExternalVideoEmbedResolver(), self::API_KEY);

        self::assertTrue($service->checkSourceAvailability('https://www.youtube.com/watch?v=dQw4w9WgXcQ'));
    }

    public function testYoutubeUnlistedEmbeddableVideoIsStillAvailable(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            '{"items":[{"id":"dQw4w9WgXcQ","status":{"privacyStatus":"unlisted","embeddable":true}}]}',
            ['http_code' => 200],
        ));
        $service = new VideoProviderImportService($client, new ExternalVideoEmbedResolver(), self::API_KEY);

        self::assertTrue($service->checkSourceAvailability('https://www.youtube.com/watch?v=dQw4w9WgXcQ'));
    }

    public function testYoutubePrivateOrNonEmbeddableVideoIsUnavailable(): void
    {
        foreach ([
            '{"items":[{"id":"dQw4w9WgXcQ","status":{"privacyStatus":"private","embeddable":true}}]}',
            '{"items":[{"id":"dQw4w9WgXcQ","status":{"privacyStatus":"public","embeddable":false}}]}',
        ] as $body) {
            $service = new VideoProviderImportService(
                new MockHttpClient([new MockResponse($body, ['http_code' => 200])]),
                new ExternalVideoEmbedResolver(),
                self::API_KEY,
            );

            self::assertFalse($service->checkSourceAvailability('https://www.youtube.com/watch?v=dQw4w9WgXcQ'));
        }
    }

    public function testUnknownUrlsAreNeverFetched(): void
    {
        $client = new MockHttpClient([]);
        $service = new VideoProviderImportService($client, new ExternalVideoEmbedResolver(), self::API_KEY);

        self::assertNull($service->checkSourceAvailability('https://attacker.example/video.mp4'));
        self::assertSame(0, $client->getRequestsCount());
    }

    public function testVimeoNotFoundIsReportedButOtherProviderErrorsFailClosed(): void
    {
        $notFound = new VideoProviderImportService(
            new MockHttpClient([new MockResponse('', ['http_code' => 404])]),
            new ExternalVideoEmbedResolver(),
            self::API_KEY,
            'vimeo-test-read-token',
        );
        self::assertFalse($notFound->checkSourceAvailability('https://vimeo.com/1234567'));

        $rateLimited = new VideoProviderImportService(
            new MockHttpClient([new MockResponse('{"error":"temporary"}', ['http_code' => 429])]),
            new ExternalVideoEmbedResolver(),
            self::API_KEY,
            'vimeo-test-read-token',
        );
        try {
            $rateLimited->checkSourceAvailability('https://vimeo.com/1234567');
            self::fail('Ein Provider-Fehler darf keine Quelle als entfernt markieren.');
        } catch (VideoProviderImportException $exception) {
            self::assertSame('Der Videoanbieter hat die Statusanfrage nicht erfolgreich beantwortet.', $exception->getMessage());
        }
    }

    public function testVimeoUnlistedVideoRequiresItsPrivacyHash(): void
    {
        $body = '{"privacy":{"view":"unlisted"}}';
        $withHash = new VideoProviderImportService(
            new MockHttpClient([new MockResponse($body, ['http_code' => 200])]),
            new ExternalVideoEmbedResolver(),
            self::API_KEY,
            'vimeo-test-read-token',
        );
        $withoutHash = new VideoProviderImportService(
            new MockHttpClient([new MockResponse($body, ['http_code' => 200])]),
            new ExternalVideoEmbedResolver(),
            self::API_KEY,
            'vimeo-test-read-token',
        );

        self::assertTrue($withHash->checkSourceAvailability('https://vimeo.com/1234567?h=privateHash1'));
        self::assertFalse($withoutHash->checkSourceAvailability('https://vimeo.com/1234567'));
    }

    public function testVimeoPublicVideoIsAvailableWithoutPrivacyHash(): void
    {
        $service = new VideoProviderImportService(
            new MockHttpClient([new MockResponse('{"privacy":{"view":"anybody"}}', ['http_code' => 200])]),
            new ExternalVideoEmbedResolver(),
            self::API_KEY,
            'vimeo-test-read-token',
        );

        self::assertTrue($service->checkSourceAvailability('https://vimeo.com/1234567'));
    }

    public function testDailymotionMustBePublishedAndEmbeddable(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            $parts = parse_url($url);
            self::assertIsArray($parts);
            self::assertSame('api.dailymotion.com', $parts['host'] ?? null);
            self::assertSame('/video/x7tgcs1', $parts['path'] ?? null);
            self::assertSame(['fields' => 'id,allow_embed,status'], $options['query'] ?? null);

            return new MockResponse('{"id":"x7tgcs1","allow_embed":false,"status":"published"}', ['http_code' => 200]);
        });
        $service = new VideoProviderImportService($client, new ExternalVideoEmbedResolver('player01'), self::API_KEY);

        self::assertFalse($service->checkSourceAvailability('https://www.dailymotion.com/video/x7tgcs1'));
    }
}
