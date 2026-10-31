<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Service\SiteLogoUploadService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class SiteLogoUploadServiceTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testStoresSanitizedRasterLogoInPrivateStorage(): void
    {
        $source = $this->png(600, 120);
        $upload = $this->uploadedFile($source, 'brand.png');
        $storedContents = null;
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage->expects(self::once())->method('write')
            ->with(
                self::callback(static fn (string $objectId): bool => 1 === preg_match('/\A[a-f0-9]{64}\z/D', $objectId)),
                self::callback(static fn (string $contents): bool => str_starts_with($contents, "\x89PNG\r\n\x1a\n")),
            )
            ->willReturnCallback(static function (string $objectId, string $contents) use (&$storedContents): void {
                $storedContents = $contents;
            });

        $metadata = new SiteLogoUploadService($storage)->store($upload);

        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $metadata['object_id']);
        self::assertSame('image/png', $metadata['mime_type']);
        self::assertIsString($storedContents);
        self::assertSame(strlen($storedContents), $metadata['size']);
        self::assertSame(hash('sha256', $storedContents), $metadata['sha256']);
        self::assertLessThanOrEqual(524288, $metadata['size']);
        $imageInfo = getimagesizefromstring($storedContents);
        self::assertIsArray($imageInfo);
        self::assertSame(466, $imageInfo[0]);
        self::assertSame(160, $imageInfo[1]);
        self::assertSame('image/png', $imageInfo['mime']);
    }

    public function testNormalizesJpegAndWebpUploadsToPng(): void
    {
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage->expects(self::exactly(2))->method('write')
            ->willReturnCallback(static function (string $objectId, string $contents): void {
                self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $objectId);
                $imageInfo = getimagesizefromstring($contents);
                self::assertIsArray($imageInfo);
                self::assertSame('image/png', $imageInfo['mime']);
                self::assertSame(466, $imageInfo[0]);
                self::assertSame(160, $imageInfo[1]);
                self::assertLessThanOrEqual(524288, strlen($contents));
            });

        $service = new SiteLogoUploadService($storage);
        foreach (['jpeg', 'webp'] as $format) {
            $metadata = $service->storeContents($this->rasterFixture($format));

            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $metadata['object_id']);
            self::assertSame('image/png', $metadata['mime_type']);
            self::assertGreaterThan(0, $metadata['size']);
            self::assertLessThanOrEqual(524288, $metadata['size']);
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $metadata['sha256']);
        }
    }

    public function testRejectsMarkupOversizedImagesAndExcessiveUploadSize(): void
    {
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage->expects(self::never())->method('write');
        $service = new SiteLogoUploadService($storage);

        try {
            $service->storeContents('<svg xmlns="http://www.w3.org/2000/svg"><script/></svg>');
            self::fail('SVG and markup must not be accepted as raster logos.');
        } catch (\InvalidArgumentException) {
        }

        try {
            $service->storeContents($this->png(2049, 1));
            self::fail('Images outside the configured dimensions must be rejected.');
        } catch (\InvalidArgumentException) {
        }

        try {
            $service->storeContents($this->png(2001, 2000));
            self::fail('Images over the configured pixel limit must be rejected.');
        } catch (\InvalidArgumentException) {
        }

        try {
            $service->storeContents(str_repeat('A', 4194305));
            self::fail('Uploads over 4 MiB must be rejected.');
        } catch (\InvalidArgumentException) {
        }
    }

    public function testRejectsUnsafeObjectIdsForDeletion(): void
    {
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage->expects(self::never())->method('delete');

        try {
            new SiteLogoUploadService($storage)->delete('../public/logo.png');
            self::fail('Arbitrary storage paths must not be deleted.');
        } catch (\InvalidArgumentException) {
        }
    }

    private function uploadedFile(string $contents, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'arcanorum-logo-upload-');
        self::assertIsString($path);
        $this->temporaryFiles[] = $path;
        self::assertSame(strlen($contents), file_put_contents($path, $contents));

        return new UploadedFile($path, $name, null, UPLOAD_ERR_OK, true);
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertInstanceOf(\GdImage::class, $image);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        if (false === $transparent) {
            throw new \RuntimeException('Could not allocate the PNG fixture color.');
        }
        imagefill($image, 0, 0, $transparent);

        ob_start();
        try {
            imagepng($image);
            $contents = ob_get_contents();
        } finally {
            ob_end_clean();
            unset($image);
        }

        self::assertIsString($contents);
        self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $contents);

        return $contents;
    }

    /** @param 'jpeg'|'webp' $format */
    private function rasterFixture(string $format): string
    {
        $image = imagecreatetruecolor(80, 40);
        self::assertInstanceOf(\GdImage::class, $image);
        $color = imagecolorallocate($image, 26, 112, 177);
        if (false === $color) {
            throw new \RuntimeException('Could not allocate the raster fixture color.');
        }
        imagefill($image, 0, 0, $color);

        ob_start();
        try {
            if ('jpeg' === $format) {
                imagejpeg($image, null, 85);
            } else {
                imagewebp($image, null, 85);
            }
            $contents = ob_get_contents();
        } finally {
            ob_end_clean();
            unset($image);
        }

        self::assertIsString($contents);
        $imageInfo = getimagesizefromstring($contents);
        self::assertIsArray($imageInfo);
        self::assertSame('image/'.$format, $imageInfo['mime']);

        return $contents;
    }
}
