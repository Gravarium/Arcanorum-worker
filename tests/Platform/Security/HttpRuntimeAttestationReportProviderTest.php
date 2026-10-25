<?php

declare(strict_types=1);

namespace App\Tests\Platform\Security;

use App\Platform\Security\HttpRuntimeAttestationReportProvider;
use App\Platform\Security\ReplayProtectionDecision;
use App\Platform\Security\RuntimeAttestationBearerToken;
use App\Platform\Security\RuntimeAttestationPolicy;
use App\Platform\Security\RuntimeAttestationReplayProtectionInterface;
use App\Platform\Security\RuntimeAttestationRequestPolicy;
use App\Platform\Security\RuntimeAttestationStatus;
use App\Platform\Security\SignedRuntimeAttestationVerifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpRuntimeAttestationReportProviderTest extends TestCase
{
    private const string TARGET_ID = 'arcanorum-production';

    private const string ENDPOINT = 'https://monitor.example.test/v1/attestations';

    private const string BEARER_TOKEN = 'test-only-attestation-token-0123456789';

    private const string KEY_ID = 'attestor-key-1';

    private string $secretKey = '';

    /** @var array<string, array{public_key: string, attestor_id: string, attestor_version: string}> */
    private array $trustedKeys = [];

    protected function setUp(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($keyPair);
        $this->trustedKeys = [
            self::KEY_ID => [
                'public_key' => self::base64UrlEncode(sodium_crypto_sign_publickey($keyPair)),
                'attestor_id' => 'external-monitor',
                'attestor_version' => '2.3.0',
            ],
        ];
    }

    public function testPolicyDigestMatchesVersionedControlMatrixBytes(): void
    {
        $matrixPath = dirname(__DIR__, 3).'/docs/sicherheit/ARC-000-KONTROLLMATRIX.json';
        $matrixContent = file_get_contents($matrixPath);
        if (false === $matrixContent) {
            throw new \LogicException('Die versionierte Kontrollmatrix fehlt.');
        }

        self::assertSame(hash('sha256', $matrixContent), RuntimeAttestationPolicy::POLICY_DIGEST);
    }

    public function testCreatesFreshChallengeAndVerifiesTheBoundSignedResponse(): void
    {
        /** @var list<array{method: string, url: string, options: array<string, mixed>, payload: array<array-key, mixed>}> $requests */
        $requests = [];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $issuedAt = $now->getTimestamp();
        $expiresAt = $issuedAt + 60;
        $replayProtection = $this->createMock(RuntimeAttestationReplayProtectionInterface::class);
        $replayProtection
            ->expects($this->once())
            ->method('consume')
            ->with(
                self::TARGET_ID,
                self::callback(static fn (mixed $nonce): bool => is_string($nonce)
                    && 1 === preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $nonce)),
                17,
                self::equalTo(new \DateTimeImmutable('@'.$expiresAt)),
            )
            ->willReturn(ReplayProtectionDecision::Accepted);

        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests, $issuedAt, $expiresAt): MockResponse {
            $body = $options['body'] ?? null;
            if (!is_string($body)) {
                throw new \LogicException('Der Attestierungsrequest besitzt keinen JSON-Body.');
            }

            $payload = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || !is_string($payload['nonce'] ?? null)) {
                throw new \LogicException('Der Attestierungsrequest besitzt keine Challenge.');
            }

            $requests[] = ['method' => $method, 'url' => $url, 'options' => $options, 'payload' => $payload];

            return self::jsonResponse(json_encode([
                'schema' => 'arcanorum.runtime-attestation-report.v1',
                'compact_jws' => $this->signedAttestation($payload['nonce'], $issuedAt, $expiresAt),
            ], JSON_THROW_ON_ERROR));
        });
        $provider = $this->createProvider($client, $replayProtection);

        $report = $provider->verifyCurrent();

        self::assertSame(RuntimeAttestationStatus::Verified, $report->status);
        self::assertTrue($report->hasFreshEvidenceAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
        self::assertCount(1, $requests);

        $request = $requests[0] ?? throw new \LogicException('Die Attestierungsanfrage fehlt.');
        self::assertSame('POST', $request['method']);
        self::assertSame(self::ENDPOINT, $request['url']);
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

        $payload = $request['payload'];
        self::assertSame(
            [
                'schema',
                'target_id',
                'policy_id',
                'policy_version',
                'policy_digest',
                'control_ids',
                'nonce',
                'requested_at',
            ],
            array_keys($payload),
        );
        self::assertSame('arcanorum.runtime-attestation-request.v1', $payload['schema'] ?? null);
        self::assertSame(self::TARGET_ID, $payload['target_id'] ?? null);
        self::assertSame(RuntimeAttestationPolicy::POLICY_ID, $payload['policy_id'] ?? null);
        self::assertSame(RuntimeAttestationPolicy::POLICY_VERSION, $payload['policy_version'] ?? null);
        self::assertSame(RuntimeAttestationPolicy::POLICY_DIGEST, $payload['policy_digest'] ?? null);
        self::assertSame(new RuntimeAttestationRequestPolicy()->allAttestedControlIds(), $payload['control_ids'] ?? null);
        $nonce = $payload['nonce'] ?? null;
        self::assertIsString($nonce);
        self::assertSame(43, strlen($nonce));
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/D', $nonce);
        self::assertIsString($payload['requested_at'] ?? null);
    }

    public function testGeneratesANewChallengeForEveryVerification(): void
    {
        /** @var list<string> $nonces */
        $nonces = [];
        $issuedAt = time();
        $expiresAt = $issuedAt + 60;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$nonces, $issuedAt, $expiresAt): MockResponse {
            $body = $options['body'] ?? null;
            if (!is_string($body)) {
                throw new \LogicException('Der Attestierungsrequest besitzt keinen JSON-Body.');
            }

            $payload = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
            $nonce = is_array($payload) ? ($payload['nonce'] ?? null) : null;
            if (!is_string($nonce)) {
                throw new \LogicException('Der Attestierungsrequest besitzt keine Challenge.');
            }

            $nonces[] = $nonce;

            return self::jsonResponse(json_encode([
                'schema' => 'arcanorum.runtime-attestation-report.v1',
                'compact_jws' => $this->signedAttestation($nonce, $issuedAt, $expiresAt),
            ], JSON_THROW_ON_ERROR));
        });
        $replayProtection = self::createStub(RuntimeAttestationReplayProtectionInterface::class);
        $replayProtection->method('consume')->willReturn(ReplayProtectionDecision::Accepted);
        $provider = $this->createProvider($client, $replayProtection);

        self::assertSame(RuntimeAttestationStatus::Verified, $provider->verifyCurrent()->status);
        self::assertSame(RuntimeAttestationStatus::Verified, $provider->verifyCurrent()->status);
        self::assertCount(2, $nonces);
        self::assertNotSame($nonces[0] ?? null, $nonces[1] ?? null);
    }

    public function testFreshnessUsesVerificationTimeAfterRemoteResponse(): void
    {
        $clock = new MockClock('2026-10-12T10:00:00+00:00');
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($clock): MockResponse {
            $body = $options['body'] ?? null;
            if (!is_string($body)) {
                throw new \LogicException('Der Attestierungsrequest besitzt keinen JSON-Body.');
            }

            $payload = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($payload)
                || !is_string($payload['nonce'] ?? null)
                || !is_string($payload['requested_at'] ?? null)
            ) {
                throw new \LogicException('Der Attestierungsrequest ist unvollständig.');
            }

            $requestTime = new \DateTimeImmutable($payload['requested_at']);
            $issuedAt = $requestTime->getTimestamp() + 2;
            $expiresAt = $issuedAt + 60;
            $clock->modify('+2 seconds');

            return self::jsonResponse(json_encode([
                'schema' => 'arcanorum.runtime-attestation-report.v1',
                'compact_jws' => $this->signedAttestation($payload['nonce'], $issuedAt, $expiresAt),
            ], JSON_THROW_ON_ERROR));
        });
        $replayProtection = self::createStub(RuntimeAttestationReplayProtectionInterface::class);
        $replayProtection->method('consume')->willReturn(ReplayProtectionDecision::Accepted);
        $provider = $this->createProvider($client, $replayProtection);
        $provider->setClock($clock);

        $report = $provider->verifyCurrent();

        self::assertSame(RuntimeAttestationStatus::Verified, $report->status);
        self::assertSame($clock->now()->getTimestamp(), $report->checkedAt?->getTimestamp());
    }

    public function testChallengeMismatchFailsBeforeReplayProtection(): void
    {
        $replayProtection = $this->createMock(RuntimeAttestationReplayProtectionInterface::class);
        $replayProtection->expects($this->never())->method('consume');
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $body = $options['body'] ?? null;
            if (!is_string($body)) {
                throw new \LogicException('Der Attestierungsrequest besitzt keinen JSON-Body.');
            }

            $payload = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
            $requestNonce = is_array($payload) ? ($payload['nonce'] ?? null) : null;
            if (!is_string($requestNonce) || '' === $requestNonce) {
                throw new \LogicException('Der Attestierungsrequest besitzt keine Challenge.');
            }

            $mismatchedNonce = ('A' === $requestNonce[0] ? 'B' : 'A').substr($requestNonce, 1);
            $issuedAt = time();
            $expiresAt = $issuedAt + 60;

            return self::jsonResponse(json_encode([
                'schema' => 'arcanorum.runtime-attestation-report.v1',
                'compact_jws' => $this->signedAttestation($mismatchedNonce, $issuedAt, $expiresAt),
            ], JSON_THROW_ON_ERROR));
        });

        $report = $this->createProvider($client, $replayProtection)->verifyCurrent();

        self::assertSame(RuntimeAttestationStatus::Failed, $report->status);
        self::assertSame('attestation_challenge_mismatch', $report->reason);
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testMalformedOrUnexpectedResponseRemainsUnknownWithoutReplayCall(): void
    {
        $responses = [
            self::jsonResponse('not-json'),
            self::jsonResponse('{"schema":"arcanorum.runtime-attestation-report.v1","schema":"arcanorum.runtime-attestation-report.v1","compact_jws":"not-a-jws"}'),
            self::jsonResponse(json_encode(['schema' => 'wrong', 'compact_jws' => 'token'], JSON_THROW_ON_ERROR)),
            self::jsonResponse(json_encode([
                'schema' => 'arcanorum.runtime-attestation-report.v1',
                'compact_jws' => 'token',
                'extra' => true,
            ], JSON_THROW_ON_ERROR)),
            new MockResponse('{"schema":"arcanorum.runtime-attestation-report.v1","compact_jws":"token"}', [
                'http_code' => 200,
                'response_headers' => ['content-type: text/plain'],
            ]),
            self::jsonResponse('remote diagnostic', 503),
            new MockResponse('compressed bytes', [
                'http_code' => 200,
                'response_headers' => [
                    'content-type: application/json',
                    'content-encoding: gzip',
                ],
            ]),
            self::jsonResponse(json_encode([
                'schema' => 'arcanorum.runtime-attestation-report.v1',
                'compact_jws' => 'token',
            ], JSON_THROW_ON_ERROR), 302),
            new MockResponse('{"schema":"arcanorum.runtime-attestation-report.v1","compact_jws":"not-a-jws"}', [
                'http_code' => 200,
                'response_headers' => ['content-type: application/json', 'content-type: text/plain'],
            ]),
        ];

        foreach ($responses as $response) {
            $replayProtection = $this->createMock(RuntimeAttestationReplayProtectionInterface::class);
            $replayProtection->expects($this->never())->method('consume');
            $provider = $this->createProvider(new MockHttpClient($response), $replayProtection);

            self::assertSame(RuntimeAttestationStatus::Unknown, $provider->verifyCurrent()->status);
        }
    }

    public function testResponseLimitUsesDownloadedBytesWhenTotalSizeIsUnknown(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $onProgress = $options['on_progress'] ?? null;
            if (is_callable($onProgress)) {
                $onProgress(HttpRuntimeAttestationReportProvider::MAX_RESPONSE_BYTES + 1, -1, []);
            }

            $body = $options['body'] ?? null;
            if (!is_string($body)) {
                throw new \LogicException('Der Attestierungsrequest besitzt keinen JSON-Body.');
            }

            $payload = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || !is_string($payload['nonce'] ?? null)) {
                throw new \LogicException('Der Attestierungsrequest besitzt keine Challenge.');
            }

            $issuedAt = time();
            $expiresAt = $issuedAt + 60;

            return self::jsonResponse(json_encode([
                'schema' => 'arcanorum.runtime-attestation-report.v1',
                'compact_jws' => $this->signedAttestation($payload['nonce'], $issuedAt, $expiresAt),
            ], JSON_THROW_ON_ERROR));
        });
        $replayProtection = self::createStub(RuntimeAttestationReplayProtectionInterface::class);
        $replayProtection->method('consume')->willReturn(ReplayProtectionDecision::Accepted);

        $report = $this->createProvider($client, $replayProtection)->verifyCurrent();

        self::assertSame(RuntimeAttestationStatus::Unknown, $report->status);
    }

    public function testOversizedResponseRemainsUnknown(): void
    {
        $body = json_encode([
            'schema' => 'arcanorum.runtime-attestation-report.v1',
            'compact_jws' => 'not-a-jws',
        ], JSON_THROW_ON_ERROR).str_repeat(' ', HttpRuntimeAttestationReportProvider::MAX_RESPONSE_BYTES + 1);
        $client = new MockHttpClient(self::jsonResponse($body));
        $replayProtection = $this->createMock(RuntimeAttestationReplayProtectionInterface::class);
        $replayProtection->expects($this->never())->method('consume');

        $report = $this->createProvider($client, $replayProtection)->verifyCurrent();

        self::assertSame(RuntimeAttestationStatus::Unknown, $report->status);
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testMalformedSignedReportFailsWithoutCallingReplayProtection(): void
    {
        $client = new MockHttpClient(self::jsonResponse(json_encode([
            'schema' => 'arcanorum.runtime-attestation-report.v1',
            'compact_jws' => 'not-a-jws',
        ], JSON_THROW_ON_ERROR)));
        $replayProtection = $this->createMock(RuntimeAttestationReplayProtectionInterface::class);
        $replayProtection->expects($this->never())->method('consume');

        $report = $this->createProvider($client, $replayProtection)->verifyCurrent();

        self::assertSame(RuntimeAttestationStatus::Failed, $report->status);
        self::assertSame('jws_malformed', $report->reason);
    }

    public function testMissingOrUnsafeConfigurationDoesNotContactRemoteEndpoint(): void
    {
        $client = new MockHttpClient(self::jsonResponse(json_encode([
            'schema' => 'arcanorum.runtime-attestation-report.v1',
            'compact_jws' => 'unused',
        ], JSON_THROW_ON_ERROR)));
        $replayProtection = $this->createMock(RuntimeAttestationReplayProtectionInterface::class);
        $replayProtection->expects($this->never())->method('consume');

        /** @var list<array{0: string, 1: string, 2: string, 3: array<array-key, mixed>}> $unsafeConfigurations */
        $unsafeConfigurations = [
            ['', self::ENDPOINT, self::BEARER_TOKEN, $this->trustedKeys],
            [self::TARGET_ID, '', self::BEARER_TOKEN, $this->trustedKeys],
            [self::TARGET_ID, 'http://monitor.example.test/v1/attestations', self::BEARER_TOKEN, $this->trustedKeys],
            [self::TARGET_ID, 'https://monitor.example.test/v1/attestations?token=inline', self::BEARER_TOKEN, $this->trustedKeys],
            [self::TARGET_ID, 'https://user:password@monitor.example.test/v1/attestations', self::BEARER_TOKEN, $this->trustedKeys],
            [self::TARGET_ID, 'https://monitor.example.test/v1/attestations#fragment', self::BEARER_TOKEN, $this->trustedKeys],
            [self::TARGET_ID, 'https://monitor.example.test/v1/attestations with space', self::BEARER_TOKEN, $this->trustedKeys],
            [self::TARGET_ID, 'https://monitor.example.test/v1/attestations\\legacy', self::BEARER_TOKEN, $this->trustedKeys],
            [self::TARGET_ID, "https://monitor.example.test/v1/attestations\nlegacy", self::BEARER_TOKEN, $this->trustedKeys],
            [self::TARGET_ID, 'https://monitor.example.test:65536/v1/attestations', self::BEARER_TOKEN, $this->trustedKeys],
            [self::TARGET_ID, 'https://unlisted.example.test/v1/attestations', self::BEARER_TOKEN, $this->trustedKeys],
            [self::TARGET_ID, self::ENDPOINT, 'invalid token with spaces', $this->trustedKeys],
            [self::TARGET_ID, self::ENDPOINT, 'short', $this->trustedKeys],
            [self::TARGET_ID, self::ENDPOINT, str_repeat('A', RuntimeAttestationBearerToken::MAX_TOTAL_LENGTH).str_repeat('=', 17), $this->trustedKeys],
            [self::TARGET_ID, self::ENDPOINT, self::BEARER_TOKEN, []],
            [self::TARGET_ID, self::ENDPOINT, self::BEARER_TOKEN, ['invalid-key' => 'invalid-entry']],
            [self::TARGET_ID, self::ENDPOINT, self::BEARER_TOKEN, [self::KEY_ID => [
                'public_key' => 'invalid-public-key',
                'attestor_id' => 'external-monitor',
                'attestor_version' => '2.3.0',
            ]]],
        ];

        foreach ($unsafeConfigurations as [$targetId, $endpoint, $token, $trustedKeys]) {
            $provider = $this->createProvider($client, $replayProtection, $targetId, $endpoint, $token, $trustedKeys);

            self::assertSame(RuntimeAttestationStatus::Unknown, $provider->verifyCurrent()->status);
        }

        self::assertSame(0, $client->getRequestsCount());
    }

    /**
     * @param array<array-key, mixed>|null $trustedKeys
     * @param list<string>                 $allowedHosts
     */
    private function createProvider(
        MockHttpClient $client,
        ?RuntimeAttestationReplayProtectionInterface $replayProtection = null,
        string $targetId = self::TARGET_ID,
        string $endpoint = self::ENDPOINT,
        string $bearerToken = self::BEARER_TOKEN,
        ?array $trustedKeys = null,
        array $allowedHosts = ['monitor.example.test'],
    ): HttpRuntimeAttestationReportProvider {
        $replayProtection ??= $this->createMock(RuntimeAttestationReplayProtectionInterface::class);

        return new HttpRuntimeAttestationReportProvider(
            $client,
            new SignedRuntimeAttestationVerifier($replayProtection),
            new RuntimeAttestationRequestPolicy(),
            $targetId,
            $endpoint,
            $bearerToken,
            $trustedKeys ?? $this->trustedKeys,
            $allowedHosts,
        );
    }

    private function signedAttestation(string $nonce, int $issuedAt, int $expiresAt): string
    {
        $controlResults = [];
        foreach (new RuntimeAttestationRequestPolicy()->allAttestedControlIds() as $controlId) {
            $controlResults[$controlId] = 'verified';
        }

        $claims = [
            'target_id' => self::TARGET_ID,
            'policy_id' => RuntimeAttestationPolicy::POLICY_ID,
            'policy_version' => RuntimeAttestationPolicy::POLICY_VERSION,
            'policy_digest' => RuntimeAttestationPolicy::POLICY_DIGEST,
            'attestor_id' => 'external-monitor',
            'attestor_version' => '2.3.0',
            'control_results' => $controlResults,
            'nonce' => $nonce,
            'sequence' => 17,
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
        ];
        $header = self::base64UrlEncode(json_encode(
            ['alg' => 'EdDSA', 'kid' => self::KEY_ID, 'typ' => 'JWT'],
            JSON_THROW_ON_ERROR,
        ));
        $payload = self::base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));
        $signingInput = $header.'.'.$payload;
        $secretKey = $this->secretKey;
        if ('' === $secretKey) {
            throw new \LogicException('Der Testschlüssel wurde nicht initialisiert.');
        }

        $signature = sodium_crypto_sign_detached($signingInput, $secretKey);

        return $signingInput.'.'.self::base64UrlEncode($signature);
    }

    private static function jsonResponse(string $body, int $status = 200): MockResponse
    {
        return new MockResponse($body, [
            'http_code' => $status,
            'response_headers' => ['content-type: application/json'],
        ]);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
