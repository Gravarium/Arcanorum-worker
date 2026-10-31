<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use App\Service\VideoSocialPreviewGenerator;
use PHPUnit\Framework\TestCase;

final class VideoSocialPreviewGeneratorTest extends TestCase
{
    public function testCreatesAndReusesA1200By630JpegPreview(): void
    {
        $sourceId = str_repeat('a', 64);
        $source = $this->createPng(640, 480);
        $objects = [$sourceId => $source];
        $storage = $this->storageFor($objects);
        $generator = new VideoSocialPreviewGenerator($storage);

        $preview = $generator->generate($sourceId, 'image/png', strlen($source));
        $cachedPreview = $generator->generate($sourceId, 'image/png', strlen($source));
        $info = getimagesizefromstring($preview);

        self::assertSame($preview, $cachedPreview);
        self::assertSame('image/jpeg', new \finfo(FILEINFO_MIME_TYPE)->buffer($preview));
        self::assertIsArray($info);
        self::assertSame(1200, $info[0]);
        self::assertSame(630, $info[1]);
        self::assertLessThanOrEqual(3145728, strlen($preview));
        self::assertSame($preview, $storage->objects[VideoSocialPreviewGenerator::cacheObjectIdForSourceObjectId($sourceId)]);
        self::assertContains(
            ['object_id' => $sourceId, 'maximum_bytes' => strlen($source)],
            $storage->readLimits,
        );
        self::assertContains(
            [
                'object_id' => VideoSocialPreviewGenerator::cacheObjectIdForSourceObjectId($sourceId),
                'maximum_bytes' => 3145728,
            ],
            $storage->readLimits,
        );
        $decodedPreview = imagecreatefromstring($preview);
        self::assertInstanceOf(\GdImage::class, $decodedPreview);
        try {
            self::assertSame(255, imagecolorat($decodedPreview, 600, 315) & 0xFF);
        } finally {
            unset($decodedPreview);
        }
    }

    public function testReplacesInvalidCachedBytesInsteadOfServingThem(): void
    {
        $sourceId = str_repeat('b', 64);
        $source = $this->createPng(320, 240);
        $cacheId = VideoSocialPreviewGenerator::cacheObjectIdForSourceObjectId($sourceId);
        $objects = [$sourceId => $source, $cacheId => 'not-an-image'];
        $storage = $this->storageFor($objects);
        $generator = new VideoSocialPreviewGenerator($storage);

        $preview = $generator->generate($sourceId, 'image/png', strlen($source));

        self::assertNotSame('not-an-image', $preview);
        self::assertSame('image/jpeg', new \finfo(FILEINFO_MIME_TYPE)->buffer($storage->objects[$cacheId]));
        $info = getimagesizefromstring($preview);
        self::assertIsArray($info);
        self::assertSame(1200, $info[0]);
    }

    public function testOversizedCachedPreviewIsRejectedAtTheStorageReadBoundary(): void
    {
        $sourceId = str_repeat('f', 64);
        $source = $this->createPng(320, 240);
        $cacheId = VideoSocialPreviewGenerator::cacheObjectIdForSourceObjectId($sourceId);
        $storage = $this->storageFor([
            $sourceId => $source,
            $cacheId => str_repeat('x', 3145729),
        ]);

        $preview = new VideoSocialPreviewGenerator($storage)->generate($sourceId, 'image/png', strlen($source));

        self::assertNotSame(str_repeat('x', 3145729), $preview);
        self::assertSame(
            ['object_id' => $cacheId, 'maximum_bytes' => 3145728],
            $storage->readLimits[0],
        );
        self::assertSame(
            ['object_id' => $sourceId, 'maximum_bytes' => strlen($source)],
            $storage->readLimits[1],
        );
        self::assertSame($preview, $storage->objects[$cacheId]);
    }

    public function testGeneratesAValidSocialPreviewFromEveryAcceptedSourceFormat(): void
    {
        $gdInfo = gd_info();
        self::assertTrue($gdInfo['JPEG Support']);
        self::assertTrue($gdInfo['PNG Support']);
        self::assertTrue($gdInfo['WebP Support']);

        foreach (['image/jpeg', 'image/png', 'image/webp'] as $mimeType) {
            $sourceId = bin2hex(random_bytes(32));
            $source = $this->createImage(800, 400, $mimeType);
            $objects = [$sourceId => $source];
            $preview = new VideoSocialPreviewGenerator($this->storageFor($objects))
                ->generate($sourceId, $mimeType, strlen($source));
            $info = getimagesizefromstring($preview);

            self::assertSame('image/jpeg', new \finfo(FILEINFO_MIME_TYPE)->buffer($preview), $mimeType);
            self::assertIsArray($info);
            self::assertSame(1200, $info[0]);
            self::assertSame(630, $info[1]);
        }
    }

    public function testRejectsSourceMetadataThatDoesNotMatchStoredImage(): void
    {
        $sourceId = str_repeat('c', 64);
        $source = $this->createPng(32, 32);
        $objects = [$sourceId => $source];
        $generator = new VideoSocialPreviewGenerator($this->storageFor($objects));

        $this->expectException(PrivateStorageOperationFailed::class);
        $generator->generate($sourceId, 'image/jpeg', strlen($source));
    }

    public function testRejectsImagesBeyondTheUploadDimensionLimitBeforeDecoding(): void
    {
        $sourceId = str_repeat('d', 64);
        $source = $this->createPng(4097, 1);
        $objects = [$sourceId => $source];
        $generator = new VideoSocialPreviewGenerator($this->storageFor($objects));

        $this->expectException(PrivateStorageOperationFailed::class);
        $generator->generate($sourceId, 'image/png', strlen($source));
    }

    public function testCacheAndPublicVersionIdentifiersDoNotExposeSourceObjectId(): void
    {
        $sourceId = str_repeat('e', 64);

        $cacheId = VideoSocialPreviewGenerator::cacheObjectIdForSourceObjectId($sourceId);
        $version = VideoSocialPreviewGenerator::versionForSourceObjectId($sourceId);

        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $cacheId);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $version);
        self::assertNotSame($sourceId, $cacheId);
        self::assertNotSame($sourceId, $version);
        self::assertNotSame($cacheId, $version);
    }

    /**
     * @param array<string, string> $objects
     */
    private function storageFor(array $objects): InMemoryVideoSocialPreviewStorage
    {
        return new InMemoryVideoSocialPreviewStorage($objects);
    }

    private function createPng(int $width, int $height): string
    {
        return $this->createImage($width, $height, 'image/png');
    }

    private function createImage(int $width, int $height, string $mimeType): string
    {
        if ($width < 1 || $height < 1) {
            throw new \InvalidArgumentException('Testbilder müssen positive Abmessungen haben.');
        }

        $image = imagecreatetruecolor($width, $height);
        self::assertInstanceOf(\GdImage::class, $image);
        imagefill($image, 0, 0, 0x1A70B1);
        ob_start();
        try {
            $encoded = match ($mimeType) {
                'image/jpeg' => imagejpeg($image, null, 90),
                'image/png' => imagepng($image),
                'image/webp' => imagewebp($image, null, 90),
                default => false,
            };
            self::assertTrue($encoded);
            $contents = ob_get_contents();
        } finally {
            ob_end_clean();
            unset($image);
        }

        self::assertIsString($contents);

        return $contents;
    }
}

final class InMemoryVideoSocialPreviewStorage implements PrivateObjectStorageInterface
{
    /** @var list<array{object_id: string, maximum_bytes: int}> */
    public array $readLimits = [];

    /** @param array<string, string> $objects */
    public function __construct(public array $objects)
    {
    }

    public function write(string $objectId, string $contents): void
    {
        $this->objects[$objectId] = $contents;
    }

    public function read(string $objectId, int $maximumBytes): string
    {
        $this->readLimits[] = ['object_id' => $objectId, 'maximum_bytes' => $maximumBytes];
        if (!array_key_exists($objectId, $this->objects)) {
            throw new PrivateStorageOperationFailed();
        }

        if ($maximumBytes < 1 || strlen($this->objects[$objectId]) > $maximumBytes) {
            throw new PrivateStorageOperationFailed();
        }

        return $this->objects[$objectId];
    }

    public function delete(string $objectId): void
    {
        unset($this->objects[$objectId]);
    }
}
