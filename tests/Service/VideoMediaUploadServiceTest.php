<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Service\VideoMediaUploadService;
use PHPUnit\Framework\Constraint\RegularExpression;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class VideoMediaUploadServiceTest extends TestCase
{
    public function testStoresOnlySniffedVideoBytesUnderRandomObjectId(): void
    {
        $payload = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64);
        $path = $this->createTemporaryUpload($payload);
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage
            ->expects($this->once())
            ->method('write')
            ->with(new RegularExpression('/\A[a-f0-9]{64}\z/'), $payload);

        try {
            $upload = new UploadedFile($path, 'claimed-video.mp4', 'application/octet-stream', null, true);
            $media = new VideoMediaUploadService($storage)->store($upload);

            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $media['object_id']);
            self::assertSame('video/mp4', $media['mime_type']);
            self::assertSame(strlen($payload), $media['size']);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testRejectsNonVideoContentEvenWhenTheFilenameLooksLikeVideo(): void
    {
        $path = $this->createTemporaryUpload("%PDF-1.7\nnot a video");
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage->expects($this->never())->method('write');

        try {
            $upload = new UploadedFile($path, 'claimed-video.mp4', 'video/mp4', null, true);

            $this->expectException(\InvalidArgumentException::class);
            new VideoMediaUploadService($storage)->store($upload);
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
        new VideoMediaUploadService($storage)->delete('../public/video.mp4');
    }

    private function createTemporaryUpload(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'arcanorum-video-upload-');
        if (false === $path) {
            throw new \RuntimeException('Testdatei konnte nicht angelegt werden.');
        }
        if (false === file_put_contents($path, $contents)) {
            throw new \RuntimeException('Testdatei konnte nicht geschrieben werden.');
        }

        return $path;
    }
}
