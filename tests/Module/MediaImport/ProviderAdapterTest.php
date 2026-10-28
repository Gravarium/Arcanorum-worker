<?php

declare(strict_types=1);

namespace App\Tests\Module\MediaImport;

use App\Module\MediaImport\DailymotionProviderAdapter;
use App\Module\MediaImport\ImportSource;
use App\Module\MediaImport\MediaProvider;
use App\Module\MediaImport\ProviderEndpoint;
use App\Module\MediaImport\ProviderError;
use App\Module\MediaImport\ProviderException;
use App\Module\MediaImport\ProviderRequest;
use App\Module\MediaImport\ProviderResponse;
use App\Module\MediaImport\ProviderTransport;
use App\Module\MediaImport\SourceKind;
use App\Module\MediaImport\VimeoProviderAdapter;
use App\Module\MediaImport\YouTubeProviderAdapter;
use PHPUnit\Framework\TestCase;

final class ProviderAdapterTest extends TestCase
{
    public function testYouTubeSearchUsesFixedHttpsEndpointAndSanitizesProviderText(): void
    {
        $transport = new FakeMediaImportTransport([
            self::jsonResponse([
                'items' => [[
                    'id' => ['videoId' => 'abc12345'],
                    'snippet' => ['title' => '<b>Summer</b> &amp; more', 'description' => '<script>untrusted</script>'],
                ]],
                'nextPageToken' => 'cursor/one',
            ]),
        ]);
        $source = ImportSource::fromInput(MediaProvider::YouTube, SourceKind::Search, 'cats & dogs');

        $page = (new YouTubeProviderAdapter($transport))->fetch($source, null, 20);

        self::assertSame('https://www.googleapis.com/youtube/v3/search?part=snippet&q=cats%20%26%20dogs&type=video&maxResults=20', $transport->requests[0]->uri());
        self::assertSame('Summer & more', $page->videos[0]->title);
        self::assertSame('untrusted', $page->videos[0]->description);
        self::assertSame('https://www.youtube.com/watch?v=abc12345', $page->videos[0]->canonicalUrl());
        self::assertSame('cursor/one', $page->nextCursor);
    }

    public function testYouTubeCreatorResolvesUploadsPlaylistAndUsesBoundCursor(): void
    {
        $transport = new FakeMediaImportTransport([
            self::jsonResponse(['items' => [['contentDetails' => ['relatedPlaylists' => ['uploads' => 'UU1234567890']]]]]),
            self::jsonResponse([
                'items' => [[
                    'contentDetails' => ['videoId' => 'abc12345'],
                    'snippet' => ['title' => 'Creator video', 'description' => ''],
                ]],
                'nextPageToken' => 'page_two',
            ]),
            self::jsonResponse(['items' => [], 'nextPageToken' => null]),
        ]);
        $source = ImportSource::fromInput(MediaProvider::YouTube, SourceKind::Creator, '@sample_creator');
        $adapter = new YouTubeProviderAdapter($transport);

        $first = $adapter->fetch($source, null, 10);
        $second = $adapter->fetch($source, $first->nextCursor, 10);

        self::assertStringContainsString('/youtube/v3/channels?', $transport->requests[0]->uri());
        self::assertStringContainsString('forHandle=sample_creator', $transport->requests[0]->uri());
        self::assertStringContainsString('playlistId=UU1234567890', $transport->requests[1]->uri());
        self::assertStringContainsString('pageToken=page_two', $transport->requests[2]->uri());
        self::assertSame([], $second->videos);
        self::assertNull($second->nextCursor);
    }

    public function testVimeoPaginationDoesNotFollowProviderSuppliedNextUrl(): void
    {
        $transport = new FakeMediaImportTransport([
            self::jsonResponse([
                'data' => [['uri' => '/videos/1234', 'name' => 'Vimeo title', 'description' => '']],
                'paging' => ['next' => 'http://169.254.169.254/latest/meta-data/'],
            ]),
            self::jsonResponse(['data' => [], 'paging' => ['next' => null]]),
        ]);
        $source = ImportSource::fromInput(MediaProvider::Vimeo, SourceKind::Collection, 'https://vimeo.com/showcase/42');
        $adapter = new VimeoProviderAdapter($transport);

        $first = $adapter->fetch($source, null, 25);
        $second = $adapter->fetch($source, $first->nextCursor, 25);

        self::assertSame('https://api.vimeo.com/albums/42/videos?per_page=25&page=1&fields=uri%2Cname%2Cdescription', $transport->requests[0]->uri());
        self::assertSame('2', $first->nextCursor);
        self::assertStringContainsString('page=2', $transport->requests[1]->uri());
        self::assertStringNotContainsString('169.254.169.254', $transport->requests[1]->uri());
        self::assertNull($second->nextCursor);
    }

    public function testDailymotionV2SupportsProfilesAndPlaylistsButRejectsGlobalSearch(): void
    {
        $transport = new FakeMediaImportTransport([
            self::jsonResponse([
                'items' => [['video_id' => 'xabc123', 'title' => 'Dailymotion title', 'description' => '']],
                'has_more' => true,
            ]),
            self::jsonResponse(['items' => [], 'has_more' => false]),
        ]);
        $adapter = new DailymotionProviderAdapter($transport);
        $profile = ImportSource::fromInput(MediaProvider::Dailymotion, SourceKind::Creator, 'https://dailymotion.com/user/example');
        $playlist = ImportSource::fromInput(MediaProvider::Dailymotion, SourceKind::Collection, 'https://dailymotion.com/playlist/xplaylist');

        $first = $adapter->fetch($profile, null, 50);
        $second = $adapter->fetch($playlist, null, 50);

        self::assertStringContainsString('https://api.dailymotion.com/v2/profiles/example/videos?', $transport->requests[0]->uri());
        self::assertStringContainsString('https://api.dailymotion.com/v2/playlists/xplaylist/videos?', $transport->requests[1]->uri());
        self::assertSame('2', $first->nextCursor);
        self::assertNull($second->nextCursor);

        try {
            $adapter->fetch(ImportSource::fromInput(MediaProvider::Dailymotion, SourceKind::Search, 'cats'), null, 10);
            self::fail('Dailymotion API v2 global search was treated as available.');
        } catch (ProviderException $exception) {
            self::assertSame(ProviderError::Unsupported, $exception->failure);
        }
        self::assertCount(2, $transport->requests);
    }

    public function testEndpointBuilderCannotAcceptAnArbitraryHostOrPath(): void
    {
        $request = new ProviderRequest(ProviderEndpoint::YouTubeSearch, ['q' => 'safe query']);
        self::assertSame('https://www.googleapis.com/youtube/v3/search?q=safe%20query', $request->uri());

        try {
            new ProviderRequest(ProviderEndpoint::VimeoUserVideos, [], '../../attacker');
            self::fail('A path traversal resource identifier was accepted.');
        } catch (\InvalidArgumentException) {
            // Expected validation exception.
        }
    }

    public function testRedirectsHtmlInvalidJsonAndOversizedResponsesFailClosed(): void
    {
        $responses = [
            new ProviderResponse(200, 'application/json', '{"items":[]}', true),
            new ProviderResponse(200, 'text/html', '{"items":[]}'),
            new ProviderResponse(200, 'application/json', '<html>'),
            new ProviderResponse(200, 'application/json', str_repeat('x', ProviderResponse::MAX_BODY_BYTES + 1)),
        ];

        foreach ($responses as $response) {
            try {
                $response->json();
                self::fail('An unsafe provider response was accepted.');
            } catch (ProviderException $exception) {
                self::assertSame(ProviderError::InvalidResponse, $exception->failure);
            }
        }
    }

    public function testProviderStatusCodesAreReducedToSafeFailureCodes(): void
    {
        $cases = [
            [401, ProviderError::Unauthorized],
            [403, ProviderError::Forbidden],
            [404, ProviderError::NotFound],
            [429, ProviderError::RateLimited],
            [503, ProviderError::Unavailable],
        ];

        foreach ($cases as [$status, $expected]) {
            try {
                (new ProviderResponse($status, 'application/json', '{"secret":"never surfaced"}', retryAfterSeconds: 90000))->json();
                self::fail('A failed provider status was accepted.');
            } catch (ProviderException $exception) {
                self::assertSame($expected, $exception->failure);
                self::assertStringNotContainsString('secret', $exception->getMessage());
                if (ProviderError::RateLimited === $expected) {
                    self::assertSame(86400, $exception->retryAfterSeconds);
                }
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private static function jsonResponse(array $payload): ProviderResponse
    {
        return new ProviderResponse(
            200,
            'application/json; charset=utf-8',
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }
}

final class FakeMediaImportTransport implements ProviderTransport
{
    /** @var list<ProviderRequest> */
    public array $requests = [];

    /** @param list<ProviderResponse> $responses */
    public function __construct(private array $responses)
    {
    }

    public function send(ProviderRequest $request): ProviderResponse
    {
        $this->requests[] = $request;
        $response = array_shift($this->responses);
        if (!$response instanceof ProviderResponse) {
            throw new \RuntimeException('No fake provider response is queued.');
        }

        return $response;
    }
}
