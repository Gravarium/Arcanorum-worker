<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Service\SeriesArtworkUploadService;
use PHPUnit\Framework\Constraint\RegularExpression;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class SeriesArtworkUploadServiceTest extends TestCase
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
            $artwork = new SeriesArtworkUploadService($storage)->store($upload);

            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $artwork['object_id']);
            self::assertSame('image/png', $artwork['mime_type']);
            self::assertSame(strlen($payload), $artwork['size']);
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
            new SeriesArtworkUploadService($storage)->store($upload);
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
            new SeriesArtworkUploadService($storage)->store($upload);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
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
        $path = tempnam(sys_get_temp_dir(), 'arcanorum-series-artwork-');
        if (false === $path) {
            throw new \RuntimeException('Testdatei konnte nicht angelegt werden.');
        }
        if (false === file_put_contents($path, $contents)) {
            throw new \RuntimeException('Testdatei konnte nicht geschrieben werden.');
        }

        return $path;
    }
}
