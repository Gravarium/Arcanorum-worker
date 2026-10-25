<?php

declare(strict_types=1);

namespace App\Tests\Platform\Hosting;

use App\Platform\Hosting\CapabilityStatus;
use App\Platform\Hosting\HostingCapability;
use App\Platform\Hosting\HostingProfileDetector;
use App\Platform\Hosting\RedisCapabilityProbe;
use App\Platform\Hosting\RedisServerVersionProviderInterface;
use App\Platform\Hosting\SymfonyRedisServerVersionProvider;
use PHPUnit\Framework\TestCase;

final class RedisCapabilityProbeTest extends TestCase
{
    public function testRedisVersionEightAndPrivateLoopbackEndpointAreVerified(): void
    {
        $report = $this->reportForVersion('8.0.2');

        self::assertSame(CapabilityStatus::Verified, $report->statusFor(HostingCapability::RedisServer8));
        self::assertSame(CapabilityStatus::Verified, $report->statusFor(HostingCapability::RedisPrivateEndpoint));
    }

    public function testRedisEightOnPublicIpDoesNotVerifyPrivateEndpoint(): void
    {
        $report = $this->reportFor('redis://8.8.8.8:6379', '8.0.2');

        self::assertSame(CapabilityStatus::Verified, $report->statusFor(HostingCapability::RedisServer8));
        self::assertSame(CapabilityStatus::Failed, $report->statusFor(HostingCapability::RedisPrivateEndpoint));
    }

    public function testRedisEightOnDnsNameDoesNotClaimPrivateEndpoint(): void
    {
        $report = $this->reportFor('rediss://cache.internal:6379', '8.0.2');

        self::assertSame(CapabilityStatus::Verified, $report->statusFor(HostingCapability::RedisServer8));
        self::assertSame(CapabilityStatus::Unknown, $report->statusFor(HostingCapability::RedisPrivateEndpoint));
    }

    public function testPrivateIpv6EndpointIsVerifiedOnlyAfterSuccessfulRedisQuery(): void
    {
        $report = $this->reportFor('rediss://[fd12:3456:789a::10]:6379/0', '8.0.2');

        self::assertSame(CapabilityStatus::Verified, $report->statusFor(HostingCapability::RedisPrivateEndpoint));
    }

    public function testPrivateAddressWithoutSuccessfulRedisQueryRemainsUnavailable(): void
    {
        $probe = new RedisCapabilityProbe(
            'redis://private-user:private-password@10.4.2.8:6379',
            $this->providerReturning(null),
        );
        $report = new HostingProfileDetector([$probe])->detect();

        self::assertSame(CapabilityStatus::NotAvailable, $report->statusFor(HostingCapability::RedisPrivateEndpoint));
        self::assertStringNotContainsString(
            'private-password',
            $report->evidenceFor(HostingCapability::RedisPrivateEndpoint)->details,
        );
    }

    public function testUnsupportedRedisVersionDoesNotChangePrivateEndpointEvidence(): void
    {
        $report = $this->reportFor('redis://192.168.10.2:6379', '7.4.1');

        self::assertSame(CapabilityStatus::Failed, $report->statusFor(HostingCapability::RedisServer8));
        self::assertSame(CapabilityStatus::Verified, $report->statusFor(HostingCapability::RedisPrivateEndpoint));
    }

    public function testUnavailableRedisVersionDoesNotLeakDsn(): void
    {
        $secretDsn = 'redis://private-user:private-password@cache.internal:6379';
        $probe = new RedisCapabilityProbe($secretDsn, $this->providerReturning(null));
        $report = new HostingProfileDetector([$probe])->detect();

        self::assertSame(CapabilityStatus::NotAvailable, $report->statusFor(HostingCapability::RedisServer8));
        self::assertStringNotContainsString(
            'private-password',
            $report->evidenceFor(HostingCapability::RedisServer8)->details,
        );
        self::assertStringNotContainsString(
            'private-password',
            $report->evidenceFor(HostingCapability::RedisPrivateEndpoint)->details,
        );
    }

    public function testInvalidRedisDsnDoesNotClaimPrivateEndpoint(): void
    {
        $probe = new RedisCapabilityProbe(
            'redis://10.4.2.8:99999',
            $this->providerReturning(null),
        );
        $report = new HostingProfileDetector([$probe])->detect();

        self::assertSame(CapabilityStatus::Unknown, $report->statusFor(HostingCapability::RedisPrivateEndpoint));
    }

    public function testConfiguredRedisServiceReportsVersionEightInIntegrationEnvironment(): void
    {
        $dsn = getenv('REDIS_URL');
        if (false === $dsn || '' === trim($dsn)) {
            throw new \RuntimeException('REDIS_URL muss für den Redis-Integrationstest gesetzt sein.');
        }

        $probe = new RedisCapabilityProbe($dsn, new SymfonyRedisServerVersionProvider());
        $report = new HostingProfileDetector([$probe])->detect();

        self::assertSame(CapabilityStatus::Verified, $report->statusFor(HostingCapability::RedisServer8));
    }

    private function reportForVersion(?string $version): \App\Platform\Hosting\HostingCapabilityReport
    {
        return $this->reportFor('redis://127.0.0.1:6379', $version);
    }

    private function reportFor(string $dsn, ?string $version): \App\Platform\Hosting\HostingCapabilityReport
    {
        return new HostingProfileDetector([
            new RedisCapabilityProbe($dsn, $this->providerReturning($version)),
        ])->detect();
    }

    private function providerReturning(?string $version): RedisServerVersionProviderInterface
    {
        return new readonly class($version) implements RedisServerVersionProviderInterface {
            public function __construct(private ?string $version)
            {
            }

            public function serverVersion(string $dsn): ?string
            {
                return $this->version;
            }
        };
    }
}
