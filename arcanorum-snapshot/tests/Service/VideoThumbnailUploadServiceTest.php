<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Service\VideoSocialPreviewGenerator;
use App\Service\VideoThumbnailUploadService;
use PHPUnit\Framework\Constraint\RegularExpression;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class VideoThumbnailUploadServiceTest extends TestCase
{
    public function testStoresValidatedRasterImageUnderRandomPrivateObjectId(): void
    {
        $payload = $this->samplePng();
        $path = $this->createTemporaryUpload($payload);
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage
            ->expects($this->once())
            ->method('write')
            ->with(new RegularExpression('/\A[a-f0-9]{64}\z/'), $payload);

        try {
            $upload = new UploadedFile($path, 'claimed-image.png', 'application/octet-stream', null, true);
            $thumbnail = new VideoThumbnailUploadService($storage)->store($upload);

            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $thumbnail['object_id']);
            self::assertSame('image/png', $thumbnail['mime_type']);
            self::assertSame(strlen($payload), $thumbnail['size']);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testRejectsSvgContentEvenWhenTheFileExtensionLooksLikePng(): void
    {
        $path = $this->createTemporaryUpload('<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage->expects($this->never())->method('write');

        try {
            $upload = new UploadedFile($path, 'claimed-image.png', 'image/png', null, true);
            $this->expectException(\InvalidArgumentException::class);
            new VideoThumbnailUploadService($storage)->store($upload);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testRejectsUploadsLargerThanConfiguredLimit(): void
    {
        $path = $this->createTemporaryUpload(str_repeat('A', 4194305));
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage->expects($this->never())->method('write');

        try {
            $upload = new UploadedFile($path, 'oversized.png', 'image/png', null, true);
            $this->expectException(\InvalidArgumentException::class);
            new VideoThumbnailUploadService($storage)->store($upload);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testRejectsObjectIdsOutsideTheStorageIdentifierFormat(): void
    {
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage->expects($this->never())->method('delete');

        $this->expectException(\InvalidArgumentException::class);
        new VideoThumbnailUploadService($storage)->delete('../public/thumbnail.png');
    }

    public function testDeletesGeneratedSocialPreviewBeforeRemovingItsSource(): void
    {
        $objectId = str_repeat('f', 64);
        $deleted = [];
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage->expects(self::exactly(2))
            ->method('delete')
            ->willReturnCallback(static function (string $deletedObjectId) use (&$deleted): void {
                $deleted[] = $deletedObjectId;
            });

        new VideoThumbnailUploadService($storage)->delete($objectId);

        self::assertSame([
            VideoSocialPreviewGenerator::cacheObjectIdForSourceObjectId($objectId),
            $objectId,
        ], $deleted);
    }

    private function samplePng(): string
    {
        $payload = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/WioAAAAASUVORK5CYII=', true);
        if (!is_string($payload)) {
            throw new \LogicException('PNG-Testdatei ist ungültig.');
        }

        return $payload;
    }

    private function createTemporaryUpload(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'arcanorum-thumbnail-upload-');
        if (false === $path) {
            throw new \RuntimeException('Testdatei konnte nicht angelegt werden.');
        }
        if (false === file_put_contents($path, $contents)) {
            throw new \RuntimeException('Testdatei konnte nicht geschrieben werden.');
        }

        return $path;
    }
}
