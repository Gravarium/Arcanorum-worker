<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RedisCacheTest extends KernelTestCase
{
    public function testRealRedisWriteReadAndExpiry(): void
    {
        self::bootKernel();

        $pool = self::getContainer()->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);

        $key = 'walker_ci_' . bin2hex(random_bytes(8));
        $item = $pool->getItem($key);
        self::assertFalse($item->isHit());

        $item->set('redis-roundtrip');
        $item->expiresAfter(1);
        self::assertTrue($pool->save($item));

        $saved = $pool->getItem($key);
        self::assertTrue($saved->isHit());
        self::assertSame('redis-roundtrip', $saved->get());

        sleep(2);
        self::assertFalse($pool->getItem($key)->isHit());
    }
}
