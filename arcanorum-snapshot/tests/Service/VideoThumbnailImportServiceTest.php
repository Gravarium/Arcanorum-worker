<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Service\VideoThumbnailImportService;
use App\Service\VideoThumbnailUploadService;
use PHPUnit\Framework\Constraint\RegularExpression;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class VideoThumbnailImportServiceTest extends TestCase
{
    public function testFetchesOnlyBoundedHttpsRasterImageAndStoresItPrivately(): void
    {
        $png = $this->samplePng();
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage->expects($this->once())
            ->method('write')
            ->with(new RegularExpression('/\A[a-f0-9]{64}\z/'), $png);
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($png): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('https://images.example.test/preview.png', $url);
            self::assertSame(0, $options['max_redirects']);
            self::assertSame(3.0, $options['timeout']);
            self::assertSame(8.0, $options['max_duration']);
            self::assertFalse($options['buffer']);

            return new MockResponse($png, ['http_code' => 200]);
        });

        $thumbnail = new VideoThumbnailImportService($client, new VideoThumbnailUploadService($storage))
            ->storeFromUrl('https://images.example.test/preview.png');

        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $thumbnail['object_id']);
        self::assertSame('image/png', $thumbnail['mime_type']);
        self::assertSame(strlen($png), $thumbnail['size']);
    }

    public function testRejectsUnsafeUrlBeforeMakingARequest(): void
    {
        $client = new MockHttpClient(static function (): never {
            self::fail('An unsafe thumbnail URL must never be requested.');
        });
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage->expects($this->never())->method('write');

        $this->expectException(\InvalidArgumentException::class);
        new VideoThumbnailImportService($client, new VideoThumbnailUploadService($storage))
            ->storeFromUrl('http://127.0.0.1/private.png');
    }

    public function testRejectsRedirectsWithoutFollowingThem(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('', [
            'http_code' => 302,
            'response_headers' => ['location: https://images.example.test/redirected.png'],
        ]));
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage->expects($this->never())->method('write');

        $this->expectException(\InvalidArgumentException::class);
        new VideoThumbnailImportService($client, new VideoThumbnailUploadService($storage))
            ->storeFromUrl('https://images.example.test/redirect.png');
    }

    public function testRejectsSvgResponse(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('<svg xmlns="http://www.w3.org/2000/svg"></svg>', ['http_code' => 200]));
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage->expects($this->never())->method('write');

        $this->expectException(\InvalidArgumentException::class);
        new VideoThumbnailImportService($client, new VideoThumbnailUploadService($storage))
            ->storeFromUrl('https://images.example.test/not-an-image.png');
    }

    public function testRejectsOversizedResponse(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse(str_repeat('x', 4194305), ['http_code' => 200]));
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage->expects($this->never())->method('write');

        $this->expectException(\InvalidArgumentException::class);
        new VideoThumbnailImportService($client, new VideoThumbnailUploadService($storage))
            ->storeFromUrl('https://images.example.test/oversized.png');
    }

    private function samplePng(): string
    {
        $payload = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/WioAAAAASUVORK5CYII=', true);
        if (!is_string($payload)) {
            throw new \LogicException('PNG test fixture is invalid.');
        }

        return $payload;
    }
}
