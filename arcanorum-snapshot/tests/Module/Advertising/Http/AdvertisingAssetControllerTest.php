<?php

declare(strict_types=1);

namespace App\Tests\Module\Advertising\Http;

use App\Module\Advertising\Http\AdvertisingAssetController;
use App\Platform\Hosting\PrivateObjectStorageInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(AdvertisingAssetController::class)]
final class AdvertisingAssetControllerTest extends TestCase
{
    public function testDraftAssetCannotBeReadPublicly(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAssociative')->with(
            self::callback(static fn (string $query): bool => str_contains($query, "advertisement.status = 'active'")
                && str_contains($query, 'placement.paused = false')
                && str_contains($query, 'placement.archived = false')),
            self::callback(static function (array $parameters): bool {
                $path = $parameters['path'] ?? null;

                return is_string($path) && str_starts_with($path, '/media/ads/');
            }),
        )->willReturn(false);
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage->expects(self::never())->method('read');
        $controller = new AdvertisingAssetController($connection, $storage, new NullLogger());

        $response = $controller->show(str_repeat('a', 32), 'png', Request::create('/media/ads/'.str_repeat('a', 32).'.png'));

        self::assertSame(404, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public function testActiveAssetIsServedWithVerifiedMimeAndNoSniffHeaders(): void
    {
        $contents = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC', true);
        self::assertIsString($contents);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAssociative')->willReturn([
            'storage_object_id' => str_repeat('b', 64),
            'mime_type' => 'image/png',
            'size' => strlen($contents),
        ]);
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage->expects(self::once())->method('read')->with(str_repeat('b', 64), strlen($contents))->willReturn($contents);
        $controller = new AdvertisingAssetController($connection, $storage, new NullLogger());

        $response = $controller->show(str_repeat('a', 32), 'png', Request::create('/media/ads/'.str_repeat('a', 32).'.png'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($contents, $response->getContent());
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }
}
