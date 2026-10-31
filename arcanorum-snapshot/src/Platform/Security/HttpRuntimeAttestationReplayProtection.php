<?php

declare(strict_types=1);

namespace App\Platform\Security;

use Symfony\Component\Clock\ClockAwareTrait;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class HttpRuntimeAttestationReplayProtection implements RuntimeAttestationReplayProtectionInterface
{
    use ClockAwareTrait;

    private const string REQUEST_SCHEMA = 'arcanorum.runtime-attestation-replay.v1';

    private const string RESPONSE_SCHEMA = 'arcanorum.runtime-attestation-replay-result.v1';

    private const int MAX_RESPONSE_BYTES = 512;

    /**
     * @param list<string> $allowedHosts
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $endpoint,
        private string $bearerToken,
        private array $allowedHosts = [],
    ) {
    }

    /**
     * @phpstan-impure
     */
    public function consume(
        string $targetId,
        string $nonce,
        int $sequence,
        \DateTimeImmutable $expiresAt,
    ): ReplayProtectionDecision {
        try {
            $now = $this->now();
        } catch (\Throwable) {
            return ReplayProtectionDecision::Unavailable;
        }

        if (!$this->hasValidConfiguration()
            || 1 !== preg_match('/\A[\x21-\x7E]{1,255}\z/D', $targetId)
            || !RuntimeAttestationChallenge::isValid($nonce)
            || $sequence < 1
            || $expiresAt <= $now
            || $expiresAt > $now->modify('+'.RuntimeAttestationReport::MAX_FRESHNESS_SECONDS.' seconds')
        ) {
            return ReplayProtectionDecision::Unavailable;
        }

        try {
            $response = $this->httpClient->request('POST', $this->endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->bearerToken,
                    'Accept' => 'application/json',
                    'Accept-Encoding' => 'identity',
                ],
                'json' => [
                    'schema' => self::REQUEST_SCHEMA,
                    'target_id' => $targetId,
                    'nonce' => $nonce,
                    'sequence' => $sequence,
                    'expires_at' => $expiresAt->format(\DateTimeInterface::ATOM),
                ],
                'max_redirects' => 0,
                'verify_peer' => true,
                'verify_host' => true,
                'timeout' => 2.0,
                'max_duration' => 2.0,
                'on_progress' => static function (int $downloadedBytes, int $downloadSize, array $info): void {
                    if ($downloadedBytes > self::MAX_RESPONSE_BYTES) {
                        throw new \RuntimeException('Die Replay-Antwort überschreitet die erlaubte Größe.');
                    }
                },
            ]);

            $statusCode = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $contentTypes = $headers['content-type'] ?? [];
            if (200 !== $statusCode || 1 !== count($contentTypes)) {
                return ReplayProtectionDecision::Unavailable;
            }

            $contentType = $contentTypes[0];
            $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));
            if ('application/json' !== $mediaType) {
                return ReplayProtectionDecision::Unavailable;
            }

            $contentEncodings = $headers['content-encoding'] ?? [];
            if ([] !== $contentEncodings
                && (1 !== count($contentEncodings) || 'identity' !== strtolower(trim($contentEncodings[0])))
            ) {
                return ReplayProtectionDecision::Unavailable;
            }

            $body = $response->getContent(false);
            $responseCheckedAt = $this->now();
        } catch (\Throwable) {
            return ReplayProtectionDecision::Unavailable;
        }

        if (strlen($body) > self::MAX_RESPONSE_BYTES || $expiresAt <= $responseCheckedAt) {
            return ReplayProtectionDecision::Unavailable;
        }

        $payload = StrictJsonObjectDecoder::decode($body, 8);
        if (null === $payload
            || 2 !== count($payload)
            || self::RESPONSE_SCHEMA !== ($payload['schema'] ?? null)
            || !is_string($payload['decision'] ?? null)
        ) {
            return ReplayProtectionDecision::Unavailable;
        }

        return match ($payload['decision']) {
            'accepted' => ReplayProtectionDecision::Accepted,
            'replay_detected' => ReplayProtectionDecision::ReplayDetected,
            default => ReplayProtectionDecision::Unavailable,
        };
    }

    private function hasValidConfiguration(): bool
    {
        return RuntimeAttestationEndpoint::isValid($this->endpoint, $this->allowedHosts)
            && RuntimeAttestationBearerToken::isValid($this->bearerToken);
    }
}
