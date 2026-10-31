<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Service\VideoPlayerWatermarkUploadService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class VideoPlayerWatermarkUploadServiceTest extends TestCase
{
    private ?string $temporaryFile = null;

    protected function tearDown(): void
    {
        if (null !== $this->temporaryFile && is_file($this->temporaryFile)) {
            unlink($this->temporaryFile);
        }
    }

    public function testStoresValidatedRasterImageUnderPrivateObjectIdWithDigest(): void
    {
        $contents = $this->samplePng();
        $upload = $this->uploadedFile($contents, 'claimed-image.png');
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage->expects(self::once())->method('write')->with(
            self::callback(static fn (string $objectId): bool => 1 === preg_match('/\A[a-f0-9]{64}\z/D', $objectId)),
            $contents,
        );

        $asset = new VideoPlayerWatermarkUploadService($storage)->store($upload);

        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $asset['object_id']);
        self::assertSame('image/png', $asset['mime_type']);
        self::assertSame(strlen($contents), $asset['size']);
        self::assertSame(hash('sha256', $contents), $asset['sha256']);
    }

    public function testRejectsMarkupDisguisedAsAnImage(): void
    {
        $upload = $this->uploadedFile('<script>not an image</script>', 'claimed-image.png');
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage->expects(self::never())->method('write');

        $this->expectException(\InvalidArgumentException::class);
        new VideoPlayerWatermarkUploadService($storage)->store($upload);
    }

    public function testRejectsUploadsAboveTwoMiB(): void
    {
        $upload = $this->uploadedFile(str_repeat('A', 2097153), 'oversized.png');
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage->expects(self::never())->method('write');

        $this->expectException(\InvalidArgumentException::class);
        new VideoPlayerWatermarkUploadService($storage)->store($upload);
    }

    public function testRejectsObjectIdsOutsideThePrivateStorageFormat(): void
    {
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage->expects(self::never())->method('delete');

        $this->expectException(\InvalidArgumentException::class);
        new VideoPlayerWatermarkUploadService($storage)->delete('../public/watermark.png');
    }

    private function samplePng(): string
    {
        $payload = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/WioAAAAASUVORK5CYII=', true);
        if (!is_string($payload)) {
            throw new \LogicException('PNG-Testdatei ist ungültig.');
        }

        return $payload;
    }

    private function uploadedFile(string $contents, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'arcanorum-watermark-upload-');
        self::assertIsString($path);
        $this->temporaryFile = $path;
        self::assertSame(strlen($contents), file_put_contents($path, $contents));

        return new UploadedFile($path, $name, null, UPLOAD_ERR_OK, true);
    }
}
