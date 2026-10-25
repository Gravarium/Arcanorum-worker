<?php

declare(strict_types=1);

namespace App\Platform\Security;

use Symfony\Component\Clock\ClockAwareTrait;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class HttpRuntimeAttestationReportProvider implements RuntimeAttestationReportProviderInterface
{
    use ClockAwareTrait;

    private const string REQUEST_SCHEMA = 'arcanorum.runtime-attestation-request.v1';

    private const string RESPONSE_SCHEMA = 'arcanorum.runtime-attestation-report.v1';

    public const int MAX_RESPONSE_BYTES = SignedRuntimeAttestationVerifier::MAX_COMPACT_JWS_LENGTH + 512;

    /**
     * @param array<array-key, mixed> $trustedKeys
     * @param list<string>            $allowedHosts
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private SignedRuntimeAttestationVerifier $verifier,
        private RuntimeAttestationRequestPolicy $requestPolicy,
        private string $targetId,
        private string $endpoint,
        private string $bearerToken,
        private array $trustedKeys,
        private array $allowedHosts = [],
    ) {
    }

    /**
     * @phpstan-impure
     */
    public function verifyCurrent(): RuntimeAttestationReport
    {
        try {
            $policy = new RuntimeAttestationPolicy(
                $this->targetId,
                RuntimeAttestationPolicy::POLICY_ID,
                RuntimeAttestationPolicy::POLICY_VERSION,
                RuntimeAttestationPolicy::POLICY_DIGEST,
                $this->requestPolicy->allAttestedControlIds(),
            );
        } catch (\Throwable) {
            return $this->unknown('attestation_configuration_invalid');
        }

        if (!$this->hasValidEndpointConfiguration() || !$this->hasValidTrustedKeys()) {
            return $this->unknown('attestation_not_configured');
        }

        try {
            $nonce = RuntimeAttestationChallenge::generate();
            $requestedAt = $this->now();
        } catch (\Throwable) {
            return $this->unknown('attestation_challenge_unavailable');
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
                    'target_id' => $policy->targetId,
                    'policy_id' => $policy->policyId,
                    'policy_version' => $policy->policyVersion,
                    'policy_digest' => $policy->policyDigest,
                    'control_ids' => $policy->requiredControlIds,
                    'nonce' => $nonce,
                    'requested_at' => $requestedAt->format(\DateTimeInterface::ATOM),
                ],
                'max_redirects' => 0,
                'verify_peer' => true,
                'verify_host' => true,
                'timeout' => 2.0,
                'max_duration' => 2.0,
                'on_progress' => static function (int $downloadedBytes, int $downloadSize, array $info): void {
                    if ($downloadedBytes > self::MAX_RESPONSE_BYTES) {
                        throw new \RuntimeException('Die Attestierungsantwort überschreitet die erlaubte Größe.');
                    }
                },
            ]);

            if (200 !== $response->getStatusCode()) {
                return $this->unknown('attestation_endpoint_unavailable');
            }

            $headers = $response->getHeaders(false);
            $contentTypes = $headers['content-type'] ?? [];
            if (1 !== count($contentTypes)) {
                return $this->unknown('attestation_response_invalid');
            }

            $contentType = $contentTypes[0];
            $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));
            if ('application/json' !== $mediaType) {
                return $this->unknown('attestation_response_invalid');
            }

            $contentEncodings = $headers['content-encoding'] ?? [];
            if ([] !== $contentEncodings
                && (1 !== count($contentEncodings) || 'identity' !== strtolower(trim($contentEncodings[0])))
            ) {
                return $this->unknown('attestation_response_compressed');
            }

            $body = $response->getContent(false);
        } catch (\Throwable) {
            return $this->unknown('attestation_endpoint_unavailable');
        }

        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            return $this->unknown('attestation_response_too_large');
        }

        $payload = StrictJsonObjectDecoder::decode($body, 4);
        $compactJws = null === $payload ? null : ($payload['compact_jws'] ?? null);
        if (null === $payload
            || 2 !== count($payload)
            || self::RESPONSE_SCHEMA !== ($payload['schema'] ?? null)
            || !is_string($compactJws)
            || '' === $compactJws
            || strlen($compactJws) > SignedRuntimeAttestationVerifier::MAX_COMPACT_JWS_LENGTH
        ) {
            return $this->unknown('attestation_response_invalid');
        }

        try {
            return $this->verifier->verify(
                $compactJws,
                $policy,
                $this->trustedKeys,
                $nonce,
                $this->now(),
            );
        } catch (\Throwable) {
            return $this->unknown('attestation_verification_unavailable');
        }
    }

    private function hasValidEndpointConfiguration(): bool
    {
        return RuntimeAttestationEndpoint::isValid($this->endpoint, $this->allowedHosts)
            && RuntimeAttestationBearerToken::isValid($this->bearerToken);
    }

    private function hasValidTrustedKeys(): bool
    {
        if ([] === $this->trustedKeys || array_is_list($this->trustedKeys) || count($this->trustedKeys) > 16) {
            return false;
        }

        foreach ($this->trustedKeys as $keyId => $trustedKey) {
            if (!is_string($keyId)
                || 1 !== preg_match('/\A[A-Za-z0-9._-]{1,64}\z/D', $keyId)
                || !is_array($trustedKey)
                || array_is_list($trustedKey)
                || 3 !== count($trustedKey)
                || [] !== array_diff(array_keys($trustedKey), ['public_key', 'attestor_id', 'attestor_version'])
            ) {
                return false;
            }

            $publicKey = $trustedKey['public_key'] ?? null;
            $attestorId = $trustedKey['attestor_id'] ?? null;
            $attestorVersion = $trustedKey['attestor_version'] ?? null;
            if (!is_string($publicKey)
                || 1 !== preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $publicKey)
                || !is_string($attestorId)
                || 1 !== preg_match('/\A[\x21-\x7E]{1,255}\z/D', $attestorId)
                || !is_string($attestorVersion)
                || 1 !== preg_match('/\A[\x21-\x7E]{1,255}\z/D', $attestorVersion)
            ) {
                return false;
            }

            $decodedPublicKey = base64_decode(strtr($publicKey, '-_', '+/').'=', true);
            if (false === $decodedPublicKey
                || 32 !== strlen($decodedPublicKey)
                || self::encodeBase64Url($decodedPublicKey) !== $publicKey
            ) {
                return false;
            }
        }

        return true;
    }

    private function unknown(string $reason): RuntimeAttestationReport
    {
        $controlStatuses = [];
        foreach ($this->requestPolicy->allAttestedControlIds() as $controlId) {
            $controlStatuses[$controlId] = RuntimeAttestationStatus::Unknown;
        }

        return new RuntimeAttestationReport(RuntimeAttestationStatus::Unknown, $controlStatuses, $reason);
    }

    private static function encodeBase64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
