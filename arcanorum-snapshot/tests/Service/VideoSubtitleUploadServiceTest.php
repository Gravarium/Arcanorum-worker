<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Service\VideoSubtitleUploadService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class VideoSubtitleUploadServiceTest extends TestCase
{
    public function testStoresNormalizedWebVttWithRandomPrivateObjectIdAndChecksum(): void
    {
        $contents = "WEBVTT\r\n\r\n00:00:00.000 --> 00:00:01.000\r\nHello.\r\n";
        $path = $this->createTemporaryUpload($contents);
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage->expects(self::once())
            ->method('write')
            ->with(self::matchesRegularExpression('/\A[a-f0-9]{64}\z/'), "WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nHello.\n");

        try {
            $stored = new VideoSubtitleUploadService($storage)->store(
                new UploadedFile($path, 'captions.vtt', 'text/vtt', null, true),
            );

            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $stored['object_id']);
            self::assertSame(strlen("WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nHello.\n"), $stored['size']);
            self::assertSame(hash('sha256', "WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nHello.\n"), $stored['sha256']);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testRejectsMarkupBeforeStorage(): void
    {
        $path = $this->createTemporaryUpload("WEBVTT\n\n00:00:00.000 --> 00:00:01.000\n<script>alert(1)</script>\n");
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage->expects(self::never())->method('write');

        try {
            $this->expectException(\InvalidArgumentException::class);
            new VideoSubtitleUploadService($storage)->store(
                new UploadedFile($path, 'captions.vtt', 'text/vtt', null, true),
            );
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testRejectsReversedCueTimingBeforeStorage(): void
    {
        $path = $this->createTemporaryUpload("WEBVTT\n\n00:00:02.000 --> 00:00:01.000\nReversed timing.\n");
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage->expects(self::never())->method('write');

        try {
            $this->expectException(\InvalidArgumentException::class);
            new VideoSubtitleUploadService($storage)->store(
                new UploadedFile($path, 'captions.vtt', 'text/vtt', null, true),
            );
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testRejectsWrongExtensionBeforeStorage(): void
    {
        $path = $this->createTemporaryUpload("WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nCaption.\n");
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage->expects(self::never())->method('write');

        try {
            $this->expectException(\InvalidArgumentException::class);
            new VideoSubtitleUploadService($storage)->store(
                new UploadedFile($path, 'captions.txt', 'text/plain', null, true),
            );
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testRejectsOversizedFileBeforeStorage(): void
    {
        $path = $this->createTemporaryUpload("WEBVTT\n".str_repeat('x', 1048576));
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage->expects(self::never())->method('write');

        try {
            $this->expectException(\InvalidArgumentException::class);
            new VideoSubtitleUploadService($storage)->store(
                new UploadedFile($path, 'captions.vtt', 'text/vtt', null, true),
            );
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function createTemporaryUpload(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'arcanorum-subtitle-upload-');
        if (false === $path) {
            throw new \RuntimeException('Testdatei konnte nicht angelegt werden.');
        }
        if (false === file_put_contents($path, $contents)) {
            throw new \RuntimeException('Testdatei konnte nicht geschrieben werden.');
        }

        return $path;
    }
}
