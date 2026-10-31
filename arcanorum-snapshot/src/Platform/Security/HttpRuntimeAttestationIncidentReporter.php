<?php

declare(strict_types=1);

namespace App\Platform\Security;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class HttpRuntimeAttestationIncidentReporter implements RuntimeAttestationIncidentReporterInterface
{
    private const string EVENT_SCHEMA = 'arcanorum.runtime-attestation-incident.v1';

    private const int MAX_RESPONSE_BYTES = 512;

    /**
     * @param list<string> $allowedHosts
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $targetId,
        private string $endpoint,
        private string $bearerToken,
        private array $allowedHosts = [],
    ) {
    }

    /**
     * @param array<array-key, mixed> $requiredControlIds
     */
    public function report(
        string $actionId,
        RuntimeAttestationStatus $status,
        array $requiredControlIds,
    ): void {
        $this->validateConfiguration();
        $this->validateIncident($actionId, $status, $requiredControlIds);

        $occurredAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        try {
            $response = $this->httpClient->request('POST', $this->endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->bearerToken,
                    'Accept' => 'application/json',
                    'Accept-Encoding' => 'identity',
                ],
                'json' => [
                    'schema' => self::EVENT_SCHEMA,
                    'event' => 'runtime_attestation_blocked',
                    'target_id' => $this->targetId,
                    'action' => $actionId,
                    'status' => $status->value,
                    'required_control_ids' => $requiredControlIds,
                    'occurred_at' => $occurredAt->format(\DateTimeInterface::ATOM),
                ],
                'max_redirects' => 0,
                'verify_peer' => true,
                'verify_host' => true,
                'timeout' => 2.0,
                'max_duration' => 2.0,
                'on_progress' => static function (int $downloadedBytes, int $downloadSize, array $info): void {
                    if ($downloadedBytes > self::MAX_RESPONSE_BYTES) {
                        throw new \RuntimeException('Die Sicherheitsmeldung überschreitet die erlaubte Antwortgröße.');
                    }
                },
            ]);
            $statusCode = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $contentEncodings = $headers['content-encoding'] ?? [];
            if ([] !== $contentEncodings
                && (1 !== count($contentEncodings) || 'identity' !== strtolower(trim($contentEncodings[0])))
            ) {
                throw new \RuntimeException('Die Sicherheitsmeldung enthält eine komprimierte Antwort.');
            }

            $body = $response->getContent(false);
            if (strlen($body) > self::MAX_RESPONSE_BYTES) {
                throw new \RuntimeException('Die Sicherheitsmeldung überschreitet die erlaubte Antwortgröße.');
            }
        } catch (\Throwable) {
            throw new RuntimeAttestationIncidentDeliveryUnavailable();
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeAttestationIncidentDeliveryUnavailable();
        }
    }

    private function validateConfiguration(): void
    {
        if (1 !== preg_match('/\A[\x21-\x7E]{1,255}\z/D', $this->targetId)
            || !RuntimeAttestationEndpoint::isValid($this->endpoint, $this->allowedHosts)
            || !RuntimeAttestationBearerToken::isValid($this->bearerToken)
        ) {
            throw new RuntimeAttestationIncidentDeliveryUnavailable();
        }
    }

    /**
     * @param array<array-key, mixed> $requiredControlIds
     */
    private function validateIncident(
        string $actionId,
        RuntimeAttestationStatus $status,
        array $requiredControlIds,
    ): void {
        if (1 !== preg_match('/\A[a-z][a-z0-9._:-]{0,127}\z/D', $actionId)
            || RuntimeAttestationStatus::Verified === $status
            || [] === $requiredControlIds
            || !array_is_list($requiredControlIds)
        ) {
            throw new \InvalidArgumentException('Die Sicherheitsmeldung enthält ungültige Aktionsdaten.');
        }

        $seen = [];
        foreach ($requiredControlIds as $controlId) {
            if (!is_string($controlId)
                || 1 !== preg_match('/\A[A-Z][A-Z0-9-]{0,63}\z/D', $controlId)
                || isset($seen[$controlId])
            ) {
                throw new \InvalidArgumentException('Die Sicherheitsmeldung enthält ungültige Kontrollkennungen.');
            }

            $seen[$controlId] = true;
        }
    }
}
