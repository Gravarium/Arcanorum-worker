<?php

declare(strict_types=1);

namespace App\Module\Member\Security;

use App\Platform\Security\StrictJsonObjectDecoder;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class BoundedOAuthJsonClient
{
    public function __construct(private HttpClientInterface $httpClient)
    {
    }

    /** @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function requestObject(string $method, string $url, array $options, string $failureMessage): array
    {
        $response = $this->httpClient->request($method, $url, $options + [
            'max_redirects' => 0,
            'timeout' => 5,
            'max_duration' => 8,
            'headers' => ['Accept' => 'application/json', 'Accept-Encoding' => 'identity'],
        ]);

        if (200 !== $response->getStatusCode()) {
            $response->cancel();
            throw new \RuntimeException($failureMessage);
        }

        $headers = $response->getHeaders(false);
        $contentTypes = $headers['content-type'] ?? [];
        $contentEncodings = $headers['content-encoding'] ?? [];
        $contentLengths = $headers['content-length'] ?? [];
        if (1 !== count($contentTypes)
            || 'application/json' !== strtolower(trim(explode(';', $contentTypes[0], 2)[0]))
            || count($contentEncodings) > 1
            || ([] !== $contentEncodings && !in_array(strtolower(trim($contentEncodings[0])), ['', 'identity'], true))
            || count($contentLengths) > 1
            || ([] !== $contentLengths && (1 !== preg_match('/\A[0-9]{1,6}\z/D', $contentLengths[0]) || (int) $contentLengths[0] > 16384))
        ) {
            $response->cancel();
            throw new \RuntimeException('Der Anmeldedienst hat eine unerwartete Antwort geliefert.');
        }

        $body = $this->readBoundedBody($response);
        if (null === StrictJsonObjectDecoder::decode($body, 16)) {
            throw new \RuntimeException('Der Anmeldedienst hat ungültige oder mehrdeutige JSON-Daten geliefert.');
        }

        try {
            $decoded = json_decode($body, false, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Der Anmeldedienst hat keine gültige JSON-Antwort geliefert.', 0, $exception);
        }
        if (!$decoded instanceof \stdClass) {
            throw new \RuntimeException('Der Anmeldedienst hat kein gültiges Profil geliefert.');
        }

        /** @var array<string, mixed> $properties */
        $properties = get_object_vars($decoded);

        return $properties;
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function requireObject(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;
        if (!$value instanceof \stdClass) {
            throw new \RuntimeException('Der Anmeldedienst hat ein unvollständiges Profil geliefert.');
        }

        /** @var array<string, mixed> $properties */
        $properties = get_object_vars($value);

        return $properties;
    }

    private function readBoundedBody(ResponseInterface $response): string
    {
        $body = '';
        foreach ($this->httpClient->stream($response, 5) as $chunk) {
            if ($chunk->isTimeout()) {
                $response->cancel();
                throw new \RuntimeException('Der Anmeldedienst hat nicht rechtzeitig geantwortet.');
            }

            $body .= $chunk->getContent();
            if (strlen($body) > 16384) {
                $response->cancel();
                throw new \RuntimeException('Der Anmeldedienst hat eine zu große Antwort geliefert.');
            }
        }

        return $body;
    }
}
