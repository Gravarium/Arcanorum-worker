<?php

declare(strict_types=1);

namespace App\Tests\Platform\Security;

use App\Platform\Security\HttpRuntimeAttestationReplayProtection;
use App\Platform\Security\ReplayProtectionDecision;
use App\Platform\Security\RuntimeAttestationBearerToken;
use App\Platform\Security\RuntimeAttestationChallenge;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpRuntimeAttestationReplayProtectionTest extends TestCase
{
    private const string TARGET_ID = 'arcanorum-production';

    private const string NONCE = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    private const int SEQUENCE = 17;

    private const string BEARER_TOKEN = 'test-only-replay-bearer-token-0123456789';

    private const int MAX_RESPONSE_BYTES = 512;

    public function testSendsBoundMinimalRequestAndAcceptsStrictRemoteDecision(): void
    {
        /** @var list<array{method: string, url: string, options: array<string, mixed>}> $requests */
        $requests = [];
        $expiry = new \DateTimeImmutable('+30 seconds', new \DateTimeZone('UTC'));
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return self::jsonResponse(json_encode([
                'schema' => 'arcanorum.runtime-attestation-replay-result.v1',
                'decision' => 'accepted',
            ], JSON_THROW_ON_ERROR));
        });
        $replayProtection = new HttpRuntimeAttestationReplayProtection(
            $client,
            'https://monitor.example.test/v1/replay/consume',
            self::BEARER_TOKEN,
            ['monitor.example.test'],
        );

        self::assertSame(
            ReplayProtectionDecision::Accepted,
            $replayProtection->consume(self::TARGET_ID, self::NONCE, self::SEQUENCE, $expiry),
        );

        self::assertCount(1, $requests);
        $request = $requests[0] ?? throw new \LogicException('Die Replay-Anfrage fehlt.');
        self::assertSame('POST', $request['method']);
        self::assertSame('https://monitor.example.test/v1/replay/consume', $request['url']);
        self::assertSame(0, $request['options']['max_redirects']);
        self::assertSame(true, $request['options']['verify_peer'] ?? null);
        self::assertSame(true, $request['options']['verify_host'] ?? null);
        self::assertSame(2.0, $request['options']['timeout']);
        self::assertSame(2.0, $request['options']['max_duration']);
        $normalizedHeaders = $request['options']['normalized_headers'] ?? null;
        self::assertIsArray($normalizedHeaders);
        self::assertSame(
            ['Authorization: Bearer '.self::BEARER_TOKEN],
            $normalizedHeaders['authorization'] ?? null,
        );
        self::assertSame(['Accept-Encoding: identity'], $normalizedHeaders['accept-encoding'] ?? null);

        $body = $request['options']['body'] ?? null;
        self::assertIsString($body);
        $payload = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('arcanorum.runtime-attestation-replay.v1', $payload['schema'] ?? null);
        self::assertSame(self::TARGET_ID, $payload['target_id'] ?? null);
        self::assertSame(self::NONCE, $payload['nonce'] ?? null);
        self::assertSame(self::SEQUENCE, $payload['sequence'] ?? null);
        self::assertSame($expiry->format(\DateTimeInterface::ATOM), $payload['expires_at'] ?? null);
        self::assertSame(
            ['schema', 'target_id', 'nonce', 'sequence', 'expires_at'],
            array_keys($payload),
        );
    }

    public function testReplayAndUnavailableRemoteDecisionsRemainDistinct(): void
    {
        $replayClient = new MockHttpClient(self::jsonResponse(json_encode([
            'schema' => 'arcanorum.runtime-attestation-replay-result.v1',
            'decision' => 'replay_detected',
        ], JSON_THROW_ON_ERROR)));
        $replayProtection = new HttpRuntimeAttestationReplayProtection(
            $replayClient,
            'https://monitor.example.test/v1/replay/consume',
            self::BEARER_TOKEN,
            ['monitor.example.test'],
        );
        $unavailableClient = new MockHttpClient(new MockResponse('remote diagnostic details', ['http_code' => 503]));
        $unavailableProtection = new HttpRuntimeAttestationReplayProtection(
            $unavailableClient,
            'https://monitor.example.test/v1/replay/consume',
            self::BEARER_TOKEN,
            ['monitor.example.test'],
        );
        $expiry = new \DateTimeImmutable('+30 seconds', new \DateTimeZone('UTC'));

        self::assertSame(
            ReplayProtectionDecision::ReplayDetected,
            $replayProtection->consume(self::TARGET_ID, self::NONCE, self::SEQUENCE, $expiry),
        );
        self::assertSame(
            ReplayProtectionDecision::Unavailable,
            $unavailableProtection->consume(self::TARGET_ID, self::NONCE, self::SEQUENCE, $expiry),
        );
    }

    public function testMalformedOrUnexpectedRemoteResponseFailsClosed(): void
    {
        $responses = [
            self::jsonResponse('not-json'),
            self::jsonResponse('{"schema":"wrong","decision":"accepted"}'),
            self::jsonResponse('{"schema":"arcanorum.runtime-attestation-replay-result.v1","decision":"accepted","extra":true}'),
            self::jsonResponse('{"schema":"arcanorum.runtime-attestation-replay-result.v1","decision":"unknown"}'),
            self::jsonResponse('{"schema":"arcanorum.runtime-attestation-replay-result.v1","decision":"replay_detected","decision":"accepted"}'),
            new MockResponse('compressed bytes', [
                'http_code' => 200,
                'response_headers' => [
                    'content-type: application/json',
                    'content-encoding: gzip',
                ],
            ]),
            new MockResponse('{"schema":"arcanorum.runtime-attestation-replay-result.v1","decision":"accepted"}', [
                'http_code' => 200,
                'response_headers' => ['content-type: application/json', 'content-type: text/plain'],
            ]),
        ];
        $expiry = new \DateTimeImmutable('+30 seconds', new \DateTimeZone('UTC'));

        foreach ($responses as $response) {
            $client = new MockHttpClient($response);
            $replayProtection = new HttpRuntimeAttestationReplayProtection(
                $client,
                'https://monitor.example.test/v1/replay/consume',
                self::BEARER_TOKEN,
                ['monitor.example.test'],
            );

            self::assertSame(
                ReplayProtectionDecision::Unavailable,
                $replayProtection->consume(self::TARGET_ID, self::NONCE, self::SEQUENCE, $expiry),
            );
        }
    }

    public function testResponseLimitUsesDownloadedBytesWhenTotalSizeIsUnknown(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            $onProgress = $options['on_progress'] ?? null;
            if (is_callable($onProgress)) {
                $onProgress(self::MAX_RESPONSE_BYTES + 1, -1, []);
            }

            return self::jsonResponse(json_encode([
                'schema' => 'arcanorum.runtime-attestation-replay-result.v1',
                'decision' => 'accepted',
            ], JSON_THROW_ON_ERROR));
        });
        $replayProtection = new HttpRuntimeAttestationReplayProtection(
            $client,
            'https://monitor.example.test/v1/replay/consume',
            self::BEARER_TOKEN,
            ['monitor.example.test'],
        );

        self::assertSame(
            ReplayProtectionDecision::Unavailable,
            $replayProtection->consume(
                self::TARGET_ID,
                self::NONCE,
                self::SEQUENCE,
                new \DateTimeImmutable('+30 seconds', new \DateTimeZone('UTC')),
            ),
        );
    }

    public function testOversizedResponseFailsClosed(): void
    {
        $body = json_encode([
            'schema' => 'arcanorum.runtime-attestation-replay-result.v1',
            'decision' => 'accepted',
        ], JSON_THROW_ON_ERROR).str_repeat(' ', 512);
        self::assertGreaterThan(512, strlen($body));

        $client = new MockHttpClient(self::jsonResponse($body));
        $replayProtection = new HttpRuntimeAttestationReplayProtection(
            $client,
            'https://monitor.example.test/v1/replay/consume',
            self::BEARER_TOKEN,
            ['monitor.example.test'],
        );

        self::assertSame(
            ReplayProtectionDecision::Unavailable,
            $replayProtection->consume(
                self::TARGET_ID,
                self::NONCE,
                self::SEQUENCE,
                new \DateTimeImmutable('+30 seconds', new \DateTimeZone('UTC')),
            ),
        );
    }

    public function testMissingOrUnsafeConfigurationDoesNotContactRemoteEndpoint(): void
    {
        $client = new MockHttpClient(self::jsonResponse(
            '{"schema":"arcanorum.runtime-attestation-replay-result.v1","decision":"accepted"}',
        ));
        $configurations = [
            ['', self::BEARER_TOKEN],
            ['http://monitor.example.test/v1/replay/consume', self::BEARER_TOKEN],
            ['https://monitor.example.test/v1/replay/consume?token=inline', self::BEARER_TOKEN],
            ['https://user:password@monitor.example.test/v1/replay/consume', self::BEARER_TOKEN],
            ['https://monitor.example.test/v1/replay/consume#fragment', self::BEARER_TOKEN],
            ['https://monitor.example.test/v1/replay/consume with space', self::BEARER_TOKEN],
            ['https://monitor.example.test/v1/replay/consume\\legacy', self::BEARER_TOKEN],
            ["https://monitor.example.test/v1/replay/consume\nlegacy", self::BEARER_TOKEN],
            ['https://monitor.example.test:65536/v1/replay/consume', self::BEARER_TOKEN],
            ['https://monitor.example.test/v1/replay/consume', 'invalid token with spaces'],
            ['https://monitor.example.test/v1/replay/consume', 'short'],
            ['https://monitor.example.test/v1/replay/consume', str_repeat('A', RuntimeAttestationBearerToken::MAX_TOTAL_LENGTH).str_repeat('=', 17)],
            ['https://unlisted.example.test/v1/replay/consume', self::BEARER_TOKEN],
        ];

        foreach ($configurations as [$endpoint, $token]) {
            $replayProtection = new HttpRuntimeAttestationReplayProtection($client, $endpoint, $token, ['monitor.example.test']);

            self::assertSame(
                ReplayProtectionDecision::Unavailable,
                $replayProtection->consume(
                    self::TARGET_ID,
                    self::NONCE,
                    self::SEQUENCE,
                    new \DateTimeImmutable('+30 seconds', new \DateTimeZone('UTC')),
                ),
            );
        }

        self::assertSame(0, $client->getRequestsCount());
    }

    public function testExpiredOrMalformedChallengeIsRejectedBeforeTheRequest(): void
    {
        $client = new MockHttpClient(new MockResponse(
            '{"schema":"arcanorum.runtime-attestation-replay-result.v1","decision":"accepted"}',
            ['http_code' => 200],
        ));
        $replayProtection = new HttpRuntimeAttestationReplayProtection(
            $client,
            'https://monitor.example.test/v1/replay/consume',
            self::BEARER_TOKEN,
            ['monitor.example.test'],
        );

        foreach (['not a valid challenge!', str_repeat('A', RuntimeAttestationChallenge::BYTE_LENGTH), str_repeat('B', 43)] as $invalidChallenge) {
            self::assertSame(
                ReplayProtectionDecision::Unavailable,
                $replayProtection->consume(
                    self::TARGET_ID,
                    $invalidChallenge,
                    self::SEQUENCE,
                    new \DateTimeImmutable('+30 seconds', new \DateTimeZone('UTC')),
                ),
            );
        }
        self::assertSame(
            ReplayProtectionDecision::Unavailable,
            $replayProtection->consume(
                self::TARGET_ID,
                self::NONCE,
                self::SEQUENCE,
                new \DateTimeImmutable('-30 seconds', new \DateTimeZone('UTC')),
            ),
        );
        self::assertSame(0, $client->getRequestsCount());
    }

    public function testUnexpectedSuccessStatusAndNonJsonContentTypeFailClosed(): void
    {
        $body = json_encode([
            'schema' => 'arcanorum.runtime-attestation-replay-result.v1',
            'decision' => 'accepted',
        ], JSON_THROW_ON_ERROR);
        $responses = [
            self::jsonResponse($body, 201),
            new MockResponse($body, [
                'http_code' => 200,
                'response_headers' => ['content-type: text/plain'],
            ]),
        ];

        foreach ($responses as $response) {
            $client = new MockHttpClient($response);
            $replayProtection = new HttpRuntimeAttestationReplayProtection(
                $client,
                'https://monitor.example.test/v1/replay/consume',
                self::BEARER_TOKEN,
                ['monitor.example.test'],
            );

            self::assertSame(
                ReplayProtectionDecision::Unavailable,
                $replayProtection->consume(
                    self::TARGET_ID,
                    self::NONCE,
                    self::SEQUENCE,
                    new \DateTimeImmutable('+30 seconds', new \DateTimeZone('UTC')),
                ),
            );
        }
    }

    public function testReplayExpiryIsBoundedAtTheExactFreshnessLimit(): void
    {
        $clock = new MockClock('2026-10-12T15:00:00+00:00');
        $now = $clock->now();
        $expiries = [
            [$now->modify('-1 microsecond'), ReplayProtectionDecision::Unavailable, 0],
            [$now, ReplayProtectionDecision::Unavailable, 0],
            [$now->modify('+1 second'), ReplayProtectionDecision::Accepted, 1],
            [$now->modify('+60 seconds'), ReplayProtectionDecision::Accepted, 1],
            [$now->modify('+60 seconds +1 microsecond'), ReplayProtectionDecision::Unavailable, 0],
            [$now->modify('+1 year'), ReplayProtectionDecision::Unavailable, 0],
        ];

        foreach ($expiries as [$expiry, $expectedDecision, $expectedRequests]) {
            $client = new MockHttpClient(self::jsonResponse(
                '{"schema":"arcanorum.runtime-attestation-replay-result.v1","decision":"accepted"}',
            ));
            $replayProtection = new HttpRuntimeAttestationReplayProtection(
                $client,
                'https://monitor.example.test/v1/replay/consume',
                self::BEARER_TOKEN,
                ['monitor.example.test'],
            );
            $replayProtection->setClock($clock);

            self::assertSame(
                $expectedDecision,
                $replayProtection->consume(self::TARGET_ID, self::NONCE, self::SEQUENCE, $expiry),
            );
            self::assertSame($expectedRequests, $client->getRequestsCount());
        }
    }

    public function testExpiryIsRecheckedAfterTheRemoteResponse(): void
    {
        $cases = [
            [0.5, ReplayProtectionDecision::Accepted],
            [1.0, ReplayProtectionDecision::Unavailable],
            [2.0, ReplayProtectionDecision::Unavailable],
        ];

        foreach ($cases as [$responseDelay, $expectedDecision]) {
            $clock = new MockClock('2026-10-12T15:00:00+00:00');
            $expiry = $clock->now()->modify('+1 second');
            $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($clock, $responseDelay): MockResponse {
                $clock->sleep($responseDelay);

                return self::jsonResponse(
                    '{"schema":"arcanorum.runtime-attestation-replay-result.v1","decision":"accepted"}',
                );
            });
            $replayProtection = new HttpRuntimeAttestationReplayProtection(
                $client,
                'https://monitor.example.test/v1/replay/consume',
                self::BEARER_TOKEN,
                ['monitor.example.test'],
            );
            $replayProtection->setClock($clock);

            self::assertSame(
                $expectedDecision,
                $replayProtection->consume(self::TARGET_ID, self::NONCE, self::SEQUENCE, $expiry),
            );
            self::assertSame(1, $client->getRequestsCount());
        }
    }

    private static function jsonResponse(string $body, int $status = 200): MockResponse
    {
        return new MockResponse($body, [
            'http_code' => $status,
            'response_headers' => ['content-type: application/json'],
        ]);
    }
}
