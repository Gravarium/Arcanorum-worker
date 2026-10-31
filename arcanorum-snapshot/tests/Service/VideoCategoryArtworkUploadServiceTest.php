<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Service\VideoCategoryArtworkUploadService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class VideoCategoryArtworkUploadServiceTest extends TestCase
{
    public function testReencodesAndResizesValidatedArtworkToThePublicDimensionLimit(): void
    {
        $path = $this->temporaryUpload($this->png(640, 360));
        $storedContents = null;
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage->expects(self::once())
            ->method('write')
            ->with(
                self::callback(static fn (string $id): bool => 1 === preg_match('/\A[a-f0-9]{64}\z/D', $id)),
                self::callback(static function (string $contents) use (&$storedContents): bool {
                    $storedContents = $contents;

                    return true;
                }),
            );

        try {
            $stored = new VideoCategoryArtworkUploadService($storage)->store(
                new UploadedFile($path, 'artwork.png', 'image/png', null, true),
            );

            self::assertSame('image/png', $stored['mime_type']);
            self::assertSame(strlen((string) $storedContents), $stored['size']);
            self::assertSame(hash('sha256', (string) $storedContents), $stored['sha256']);
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $stored['object_id']);
            $imageInfo = getimagesizefromstring((string) $storedContents);
            self::assertIsArray($imageInfo);
            self::assertSame(320, $imageInfo[0]);
            self::assertSame(180, $imageInfo[1]);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testRejectsSvgAndOversizedArtworkBeforeWritingToPrivateStorage(): void
    {
        $svgPath = $this->temporaryUpload('<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        $largePath = $this->temporaryUpload(str_repeat('x', 4194305));
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage->expects(self::never())->method('write');

        try {
            try {
                new VideoCategoryArtworkUploadService($storage)->store(
                    new UploadedFile($svgPath, 'artwork.png', 'image/png', null, true),
                );
                self::fail('SVG artwork must be rejected even when its name claims to be PNG.');
            } catch (\InvalidArgumentException) {
                self::assertFileExists($svgPath);
            }

            $this->expectException(\InvalidArgumentException::class);
            new VideoCategoryArtworkUploadService($storage)->store(
                new UploadedFile($largePath, 'large.png', 'image/png', null, true),
            );
        } finally {
            if (is_file($svgPath)) {
                unlink($svgPath);
            }
            if (is_file($largePath)) {
                unlink($largePath);
            }
        }
    }

    public function testRejectsArtworkWithAnInputDimensionAboveTheDecoderLimit(): void
    {
        $path = $this->temporaryUpload($this->png(4097, 1));
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage->expects(self::never())->method('write');

        try {
            $this->expectException(\InvalidArgumentException::class);
            new VideoCategoryArtworkUploadService($storage)->store(
                new UploadedFile($path, 'oversized-dimension.png', 'image/png', null, true),
            );
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function png(int $width, int $height): string
    {
        if ($width < 1 || $height < 1) {
            throw new \InvalidArgumentException('PNG test dimensions must be positive.');
        }

        $image = imagecreatetruecolor($width, $height);
        self::assertInstanceOf(\GdImage::class, $image);
        $color = imagecolorallocate($image, 32, 96, 144);
        self::assertNotFalse($color);
        imagefill($image, 0, 0, $color);
        ob_start();
        try {
            self::assertTrue(imagepng($image));
            $contents = ob_get_contents();
        } finally {
            ob_end_clean();
            unset($image);
        }
        self::assertIsString($contents);

        return $contents;
    }

    private function temporaryUpload(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'arcanorum-video-category-artwork-');
        if (false === $path) {
            throw new \RuntimeException('Temporary category artwork could not be created.');
        }
        if (false === file_put_contents($path, $contents)) {
            throw new \RuntimeException('Temporary category artwork could not be written.');
        }

        return $path;
    }
}
