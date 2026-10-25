<?php

declare(strict_types=1);

namespace App\Tests\Platform\Hosting;

use App\Platform\Hosting\ArrayCachePoolAdapter;
use App\Platform\Hosting\CapabilityEvidence;
use App\Platform\Hosting\CapabilityProbeInterface;
use App\Platform\Hosting\CapabilityStatus;
use App\Platform\Hosting\HostingAdapterSelector;
use App\Platform\Hosting\HostingCachePoolProvider;
use App\Platform\Hosting\HostingCapability;
use App\Platform\Hosting\HostingProfileDetector;
use App\Platform\Hosting\RedisCachePoolAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\NamespacedPoolInterface;

final class HostingCachePoolProviderTest extends TestCase
{
    public function testUnverifiedPrivateRedisEndpointUsesInMemoryFallback(): void
    {
        $provider = $this->providerFor([
            $this->evidence(HostingCapability::RedisServer8, CapabilityStatus::Verified),
        ], 'redis://127.0.0.1:6379');

        $pool = $provider->pool();

        self::assertInstanceOf(ArrayAdapter::class, $pool);
        self::assertFalse($provider->usesRedisPool());
        $item = $pool->getItem('hosting-cache-fallback');
        $item->set('available');
        self::assertTrue($pool->save($item));
        self::assertSame('available', $pool->getItem('hosting-cache-fallback')->get());
    }

    public function testSelectedPoolSupportsPsr6SymfonyCacheAndNamespacedContracts(): void
    {
        $provider = $this->providerFor(
            [$this->evidence(HostingCapability::RedisServer8, CapabilityStatus::Verified)],
            'redis://127.0.0.1:6379',
        );

        self::assertInstanceOf(CacheItemPoolInterface::class, $provider);
        self::assertInstanceOf(CacheInterface::class, $provider);
        self::assertInstanceOf(NamespacedPoolInterface::class, $provider);

        $item = $provider->getItem('provider-psr6-item');
        $item->set('stored');
        self::assertTrue($provider->save($item));
        self::assertTrue($provider->hasItem('provider-psr6-item'));

        $deferred = $provider->getItem('provider-deferred-item');
        $deferred->set('queued');
        self::assertTrue($provider->saveDeferred($deferred));
        self::assertTrue($provider->commit());

        $itemCount = 0;
        foreach ($provider->getItems(['provider-psr6-item', 'provider-deferred-item']) as $cachedItem) {
            ++$itemCount;
        }
        self::assertSame(2, $itemCount);

        $callbackCalls = 0;
        $value = $provider->get(
            'provider-contract-item',
            static function (\Psr\Cache\CacheItemInterface $cacheItem, bool $save) use (&$callbackCalls): string {
                ++$callbackCalls;

                return 'computed';
            },
        );
        self::assertSame('computed', $value);
        self::assertSame('computed', $provider->get('provider-contract-item', static fn (): string => 'unused'));
        self::assertSame(1, $callbackCalls);
        self::assertTrue($provider->delete('provider-contract-item'));

        $subNamespace = $provider->withSubNamespace('provider-child');
        $childItem = $subNamespace->getItem('provider-namespaced-item');
        $childItem->set('isolated');
        self::assertTrue($subNamespace->save($childItem));
        self::assertSame('isolated', $subNamespace->getItem('provider-namespaced-item')->get());
        self::assertFalse($provider->hasItem('provider-namespaced-item'));
        self::assertTrue($subNamespace->deleteItem('provider-namespaced-item'));

        self::assertTrue($provider->deleteItems(['provider-psr6-item', 'provider-deferred-item']));
        self::assertFalse($provider->hasItem('provider-psr6-item'));
        self::assertTrue($provider->clear());
    }

    public function testRedisPoolIsSelectedOnlyWithVerifiedVersionAndPrivateEndpoint(): void
    {
        $dsn = getenv('REDIS_URL');
        if (false === $dsn || '' === trim($dsn)) {
            throw new \RuntimeException('REDIS_URL muss für den Redis-Integrationstest gesetzt sein.');
        }

        $provider = $this->providerFor([
            $this->evidence(HostingCapability::RedisServer8, CapabilityStatus::Verified),
            $this->evidence(HostingCapability::RedisPrivateEndpoint, CapabilityStatus::Verified),
        ], $dsn);
        $pool = $provider->pool();

        self::assertInstanceOf(RedisAdapter::class, $pool);
        self::assertTrue($provider->usesRedisPool());
        $key = 'hosting-cache-'.bin2hex(random_bytes(8));

        try {
            $item = $pool->getItem($key);
            $item->set('redis-available');
            self::assertTrue($pool->save($item));
            self::assertSame('redis-available', $pool->getItem($key)->get());
        } finally {
            $pool->deleteItem($key);
        }
    }

    public function testRedisInitializationFailureUsesFallbackWithoutExposingConnectionDetails(): void
    {
        $provider = $this->providerFor([
            $this->evidence(HostingCapability::RedisServer8, CapabilityStatus::Verified),
            $this->evidence(HostingCapability::RedisPrivateEndpoint, CapabilityStatus::Verified),
        ], 'redis://private-user:private-password@127.0.0.1:1');

        $pool = $provider->pool();

        self::assertInstanceOf(ArrayAdapter::class, $pool);
        self::assertFalse($provider->usesRedisPool());
        $item = $pool->getItem('hosting-cache-after-redis-failure');
        $item->set('fallback');
        self::assertTrue($pool->save($item));
        self::assertSame('fallback', $pool->getItem('hosting-cache-after-redis-failure')->get());
    }

    /**
     * @param list<CapabilityEvidence> $evidence
     */
    private function providerFor(array $evidence, string $redisDsn): HostingCachePoolProvider
    {
        $redis = new RedisCachePoolAdapter($redisDsn);
        $fallback = new ArrayCachePoolAdapter();

        return new HostingCachePoolProvider(
            'arcanorum-test',
            new HostingAdapterSelector([$redis, $fallback]),
            new HostingProfileDetector([$this->probeWith($evidence)]),
            $fallback,
        );
    }

    private function evidence(HostingCapability $capability, CapabilityStatus $status): CapabilityEvidence
    {
        return new CapabilityEvidence(
            $capability,
            $status,
            self::class,
            'Testnachweis.',
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    /**
     * @param list<CapabilityEvidence> $evidence
     */
    private function probeWith(array $evidence): CapabilityProbeInterface
    {
        return new readonly class($evidence) implements CapabilityProbeInterface {
            /**
             * @param list<CapabilityEvidence> $evidence
             */
            public function __construct(private array $evidence)
            {
            }

            /**
             * @return iterable<CapabilityEvidence>
             */
            public function probe(): iterable
            {
                return $this->evidence;
            }
        };
    }
}
