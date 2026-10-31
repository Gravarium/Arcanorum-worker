<?php

declare(strict_types=1);

namespace App\Tests\Platform\Security;

use App\Platform\Security\HttpRuntimeAttestationIncidentReporter;
use App\Platform\Security\RuntimeAttestationBearerToken;
use App\Platform\Security\RuntimeAttestationIncidentDeliveryUnavailable;
use App\Platform\Security\RuntimeAttestationStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpRuntimeAttestationIncidentReporterTest extends TestCase
{
    private const int MAX_RESPONSE_BYTES = 512;

    public function testSendsMinimalAuthenticatedIncidentWithoutFollowingRedirects(): void
    {
        /** @var list<array{method: string, url: string, options: array<string, mixed>}> $requests */
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('', ['http_code' => 202]);
        });
        $reporter = new HttpRuntimeAttestationIncidentReporter(
            $client,
            'arcanorum-production',
            'https://monitor.example.test/v1/incidents',
            'test-only-bearer-secret-0123456789',
            ['monitor.example.test'],
        );

        $reporter->report('admin.request', RuntimeAttestationStatus::Unknown, [
            'INV-AUTH-001',
            'INV-APP-INTEGRITY-001',
        ]);

        self::assertCount(1, $requests);
        $request = $requests[0] ?? throw new \LogicException('Die Mock-Anfrage fehlt.');
        self::assertSame('POST', $request['method']);
        self::assertSame('https://monitor.example.test/v1/incidents', $request['url']);
        self::assertSame(0, $request['options']['max_redirects']);
        self::assertSame(true, $request['options']['verify_peer'] ?? null);
        self::assertSame(true, $request['options']['verify_host'] ?? null);
        self::assertSame(2.0, $request['options']['timeout']);
        self::assertSame(2.0, $request['options']['max_duration']);
        $normalizedHeaders = $request['options']['normalized_headers'] ?? null;
        self::assertIsArray($normalizedHeaders);
        self::assertSame(
            ['Authorization: Bearer test-only-bearer-secret-0123456789'],
            $normalizedHeaders['authorization'] ?? null,
        );
        self::assertSame(['Accept-Encoding: identity'], $normalizedHeaders['accept-encoding'] ?? null);

        $body = $request['options']['body'] ?? null;
        self::assertIsString($body);
        $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('arcanorum.runtime-attestation-incident.v1', $payload['schema'] ?? null);
        self::assertSame('runtime_attestation_blocked', $payload['event'] ?? null);
        self::assertSame('arcanorum-production', $payload['target_id'] ?? null);
        self::assertSame('admin.request', $payload['action'] ?? null);
        self::assertSame('unknown', $payload['status'] ?? null);
        self::assertSame(['INV-AUTH-001', 'INV-APP-INTEGRITY-001'], $payload['required_control_ids'] ?? null);
        self::assertIsString($payload['occurred_at'] ?? null);
        self::assertSame(
            ['schema', 'event', 'target_id', 'action', 'status', 'required_control_ids', 'occurred_at'],
            array_keys($payload),
        );
        self::assertStringNotContainsString('user@example.test', $body);
        self::assertStringNotContainsString('/srv/private', $body);
    }

    public function testNonHttpsConfigurationDoesNotSendAnIncident(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 202]));
        $reporter = new HttpRuntimeAttestationIncidentReporter(
            $client,
            'arcanorum-production',
            'http://monitor.example.test/v1/incidents',
            'test-only-bearer-secret-0123456789',
            ['monitor.example.test'],
        );

        try {
            $reporter->report('admin.request', RuntimeAttestationStatus::Unknown, ['INV-AUTH-001']);
            self::fail('Eine unverschlüsselte Melde-URL muss gesperrt bleiben.');
        } catch (RuntimeAttestationIncidentDeliveryUnavailable) {
            self::assertSame(0, $client->getRequestsCount());
        }
    }

    public function testMalformedEndpointDoesNotSendAnIncident(): void
    {
        foreach ([
            'https://monitor.example.test/v1/incidents with space',
            'https://monitor.example.test/v1/incidents\\legacy',
            "https://monitor.example.test/v1/incidents\nlegacy",
            'https://monitor.example.test:65536/v1/incidents',
        ] as $endpoint) {
            $client = new MockHttpClient(new MockResponse('', ['http_code' => 202]));
            $reporter = new HttpRuntimeAttestationIncidentReporter(
                $client,
                'arcanorum-production',
                $endpoint,
                'test-only-bearer-secret-0123456789',
                ['monitor.example.test'],
            );

            try {
                $reporter->report('admin.request', RuntimeAttestationStatus::Unknown, ['INV-AUTH-001']);
                self::fail('Ein ungültiger HTTPS-Endpunkt darf keine Incident-Anfrage auslösen.');
            } catch (RuntimeAttestationIncidentDeliveryUnavailable) {
                self::assertSame(0, $client->getRequestsCount());
            }
        }
    }

    public function testUnlistedHostDoesNotSendAnIncident(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 202]));
        $reporter = new HttpRuntimeAttestationIncidentReporter(
            $client,
            'arcanorum-production',
            'https://monitor.example.test/v1/incidents',
            'test-only-bearer-secret-0123456789',
            ['different.example.test'],
        );

        try {
            $reporter->report('admin.request', RuntimeAttestationStatus::Unknown, ['INV-AUTH-001']);
            self::fail('Ein nicht freigegebener Host darf keine Incident-Anfrage auslösen.');
        } catch (RuntimeAttestationIncidentDeliveryUnavailable) {
            self::assertSame(0, $client->getRequestsCount());
        }
    }

    public function testMissingConfigurationDoesNotSendAnIncident(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 202]));
        $reporter = new HttpRuntimeAttestationIncidentReporter(
            $client,
            '',
            '',
            '',
            ['monitor.example.test'],
        );

        try {
            $reporter->report('admin.request', RuntimeAttestationStatus::Unknown, ['INV-AUTH-001']);
            self::fail('Eine unvollständige Meldestellenkonfiguration muss gesperrt bleiben.');
        } catch (RuntimeAttestationIncidentDeliveryUnavailable) {
            self::assertSame(0, $client->getRequestsCount());
        }
    }

    public function testShortMalformedOrOverlongBearerTokenDoesNotSendAnIncident(): void
    {
        foreach (['short', 'test-only-bearer=secret-0123456789', str_repeat('A', RuntimeAttestationBearerToken::MAX_TOTAL_LENGTH).str_repeat('=', 17)] as $bearerToken) {
            $client = new MockHttpClient(new MockResponse('', ['http_code' => 202]));
            $reporter = new HttpRuntimeAttestationIncidentReporter(
                $client,
                'arcanorum-production',
                'https://monitor.example.test/v1/incidents',
                $bearerToken,
                ['monitor.example.test'],
            );

            try {
                $reporter->report('admin.request', RuntimeAttestationStatus::Unknown, ['INV-AUTH-001']);
                self::fail('Ein zu kurzes, syntaktisch ungültiges oder zu langes Bearer-Token darf keinen Alarm senden.');
            } catch (RuntimeAttestationIncidentDeliveryUnavailable) {
                self::assertSame(0, $client->getRequestsCount());
            }
        }
    }

    public function testResponseLimitUsesDownloadedBytesWhenTotalSizeIsUnknown(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            $onProgress = $options['on_progress'] ?? null;
            if (is_callable($onProgress)) {
                $onProgress(self::MAX_RESPONSE_BYTES + 1, -1, []);
            }

            return new MockResponse('', ['http_code' => 202]);
        });
        $reporter = new HttpRuntimeAttestationIncidentReporter(
            $client,
            'arcanorum-production',
            'https://monitor.example.test/v1/incidents',
            'test-only-bearer-secret-0123456789',
            ['monitor.example.test'],
        );

        try {
            $reporter->report('admin.request', RuntimeAttestationStatus::Unknown, ['INV-AUTH-001']);
            self::fail('Eine Antwort über dem Größenlimit muss den Meldeversand fail-closed abbrechen.');
        } catch (RuntimeAttestationIncidentDeliveryUnavailable $exception) {
            self::assertSame('Die unabhängige Sicherheitsmeldung konnte nicht übermittelt werden.', $exception->getMessage());
        }
    }

    public function testCompressedResponseIsRejected(): void
    {
        $client = new MockHttpClient(new MockResponse('compressed bytes', [
            'http_code' => 202,
            'response_headers' => ['content-encoding: gzip'],
        ]));
        $reporter = new HttpRuntimeAttestationIncidentReporter(
            $client,
            'arcanorum-production',
            'https://monitor.example.test/v1/incidents',
            'test-only-bearer-secret-0123456789',
            ['monitor.example.test'],
        );

        try {
            $reporter->report('admin.request', RuntimeAttestationStatus::Unknown, ['INV-AUTH-001']);
            self::fail('Komprimierte Meldeantworten müssen fail-closed abgewiesen werden.');
        } catch (RuntimeAttestationIncidentDeliveryUnavailable) {
            self::assertSame(1, $client->getRequestsCount());
        }
    }

    public function testRemoteFailureUsesGenericExceptionText(): void
    {
        $client = new MockHttpClient(new MockResponse('sensitive remote stack trace', ['http_code' => 503]));
        $reporter = new HttpRuntimeAttestationIncidentReporter(
            $client,
            'arcanorum-production',
            'https://monitor.example.test/v1/incidents',
            'test-only-bearer-secret-0123456789',
            ['monitor.example.test'],
        );

        try {
            $reporter->report('app:admin:create', RuntimeAttestationStatus::Failed, ['INV-AUTH-001']);
            self::fail('Ein nicht erfolgreicher Meldeendpunkt darf nicht als zugestellt gelten.');
        } catch (RuntimeAttestationIncidentDeliveryUnavailable $exception) {
            self::assertSame('Die unabhängige Sicherheitsmeldung konnte nicht übermittelt werden.', $exception->getMessage());
            self::assertStringNotContainsString('sensitive remote stack trace', $exception->getMessage());
        }
    }
}
