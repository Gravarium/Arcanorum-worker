<?php

declare(strict_types=1);

namespace App\Tests\Platform\Hosting;

use App\Platform\Hosting\CapabilityEvidence;
use App\Platform\Hosting\CapabilityStatus;
use App\Platform\Hosting\HostingAdapterAmbiguous;
use App\Platform\Hosting\HostingAdapterDefinitionInterface;
use App\Platform\Hosting\HostingAdapterSelector;
use App\Platform\Hosting\HostingAdapterUnavailable;
use App\Platform\Hosting\HostingCapability;
use App\Platform\Hosting\HostingCapabilityReport;
use App\Platform\Hosting\HostingService;
use PHPUnit\Framework\TestCase;

final class HostingAdapterSelectorTest extends TestCase
{
    public function testInvalidAdapterIdentifierIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HostingAdapterSelector([
            $this->adapter('Invalid Identifier', HostingService::Cache, [], 1),
        ]);
    }

    public function testDuplicateAdapterIdentifiersAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HostingAdapterSelector([
            $this->adapter('same.identifier', HostingService::Cache, [], 1),
            $this->adapter('same.identifier', HostingService::Queue, [], 2),
        ]);
    }

    public function testNegativeAdapterPriorityIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HostingAdapterSelector([
            $this->adapter('cache.negative', HostingService::Cache, [], -1),
        ]);
    }

    public function testDuplicateCapabilityRequirementsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HostingAdapterSelector([
            $this->adapter(
                'cache.duplicate_requirement',
                HostingService::Cache,
                [HostingCapability::RedisPrivateEndpoint, HostingCapability::RedisPrivateEndpoint],
                1,
            ),
        ]);
    }

    public function testPrimaryAdapterBeatsHigherPriorityFallbackWhenRequirementsAreMet(): void
    {
        $primary = $this->adapter('redis.cache', HostingService::Cache, [HostingCapability::RedisPrivateEndpoint], 1);
        $fallback = $this->adapter('database.cache', HostingService::Cache, [], 100, true);
        $selector = new HostingAdapterSelector([$fallback, $primary]);

        $selected = $selector->select(
            HostingService::Cache,
            $this->reportFor([HostingCapability::RedisPrivateEndpoint->value => CapabilityStatus::Verified]),
        );

        self::assertSame($primary, $selected);
    }

    public function testEqualRankedFallbackAdaptersAreRejectedAsAmbiguous(): void
    {
        $first = $this->adapter('cache.fallback_a', HostingService::Cache, [], 5, true);
        $second = $this->adapter('cache.fallback_b', HostingService::Cache, [], 5, true);
        $selector = new HostingAdapterSelector([$first, $second]);

        $this->expectException(HostingAdapterAmbiguous::class);
        $selector->select(HostingService::Cache, $this->reportFor([]));
    }

    public function testPreferredAdapterIsChosenWhenItsCapabilitiesAreVerified(): void
    {
        $preferred = $this->adapter('redis.cache', HostingService::Cache, [HostingCapability::RedisPrivateEndpoint], 10);
        $fallback = $this->adapter('database.cache', HostingService::Cache, [], 1, true);
        $selector = new HostingAdapterSelector([$fallback, $preferred]);

        $selected = $selector->select(
            HostingService::Cache,
            $this->reportFor([HostingCapability::RedisPrivateEndpoint->value => CapabilityStatus::Verified]),
        );

        self::assertSame($preferred, $selected);
    }

    public function testSafeFallbackIsSelectedWhenPreferredCapabilityIsUnproven(): void
    {
        $preferred = $this->adapter('redis.cache', HostingService::Cache, [HostingCapability::RedisPrivateEndpoint], 10);
        $fallback = $this->adapter('database.cache', HostingService::Cache, [], 1, true);
        $selector = new HostingAdapterSelector([$preferred, $fallback]);

        $selected = $selector->select(
            HostingService::Cache,
            $this->reportFor([HostingCapability::RedisPrivateEndpoint->value => CapabilityStatus::Failed]),
        );

        self::assertSame($fallback, $selected);
    }

    public function testSafeFallbackIsSelectedWhenPreferredCapabilityEvidenceIsStale(): void
    {
        $preferred = $this->adapter('redis.cache', HostingService::Cache, [HostingCapability::RedisPrivateEndpoint], 10);
        $fallback = $this->adapter('database.cache', HostingService::Cache, [], 1, true);
        $selector = new HostingAdapterSelector([$preferred, $fallback]);
        $checkedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->sub(new \DateInterval('PT61S'));

        $selected = $selector->select(
            HostingService::Cache,
            $this->reportFor(
                [HostingCapability::RedisPrivateEndpoint->value => CapabilityStatus::Verified],
                $checkedAt,
            ),
        );

        self::assertSame($fallback, $selected);
    }

    public function testServiceWithoutEligibleAdapterFailsClosed(): void
    {
        $adapter = $this->adapter('redis.queue', HostingService::Queue, [HostingCapability::RedisPrivateEndpoint], 10);
        $selector = new HostingAdapterSelector([$adapter]);

        try {
            $selector->select(HostingService::Queue, $this->reportFor([]));
            self::fail('Ein Adapter ohne bestätigte Voraussetzungen muss gesperrt bleiben.');
        } catch (HostingAdapterUnavailable $exception) {
            self::assertSame(HostingService::Queue, $exception->service);
        }
    }

    public function testEqualRankedAdaptersAreRejectedAsAmbiguous(): void
    {
        $first = $this->adapter('cache.primary_a', HostingService::Cache, [], 5);
        $second = $this->adapter('cache.primary_b', HostingService::Cache, [], 5);
        $selector = new HostingAdapterSelector([$first, $second]);

        $this->expectException(HostingAdapterAmbiguous::class);
        $selector->select(HostingService::Cache, $this->reportFor([]));
    }

    /**
     * @param list<HostingCapability> $requirements
     */
    private function adapter(
        string $identifier,
        HostingService $service,
        array $requirements,
        int $priority,
        bool $fallback = false,
    ): HostingAdapterDefinitionInterface {
        return new readonly class($identifier, $service, $requirements, $priority, $fallback) implements HostingAdapterDefinitionInterface {
            /**
             * @param list<HostingCapability> $requirements
             */
            public function __construct(
                private string $identifier,
                private HostingService $service,
                private array $requirements,
                private int $priority,
                private bool $fallback,
            ) {
            }

            public function identifier(): string
            {
                return $this->identifier;
            }

            public function service(): HostingService
            {
                return $this->service;
            }

            public function requiredCapabilities(): array
            {
                return $this->requirements;
            }

            public function priority(): int
            {
                return $this->priority;
            }

            public function isFallback(): bool
            {
                return $this->fallback;
            }
        };
    }

    /**
     * @param array<string, CapabilityStatus> $overrides
     */
    private function reportFor(array $overrides, ?\DateTimeImmutable $checkedAt = null): HostingCapabilityReport
    {
        $checkedAt ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $checks = [];

        foreach (HostingCapability::cases() as $capability) {
            $checks[$capability->value] = new CapabilityEvidence(
                $capability,
                $overrides[$capability->value] ?? CapabilityStatus::Unknown,
                self::class,
                'Testnachweis.',
                $checkedAt,
            );
        }

        return new HostingCapabilityReport($checks);
    }
}
