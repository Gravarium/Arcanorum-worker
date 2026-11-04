<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use App\Service\ContentInlineImageUploadService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ContentInlineImageUploadServiceTest extends TestCase
{
    public function testReencodesSupportedImageAndStoresPrivateDigestMetadata(): void
    {
        $storage = new InlineImageRecordingStorage();
        $service = new ContentInlineImageUploadService($storage);
        $contents = $this->png()."\nprivate metadata must be discarded";

        $stored = $service->storeContents($contents);

        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $stored['object_id']);
        self::assertSame('image/png', $stored['mime_type']);
        self::assertSame(strlen($storage->objects[$stored['object_id']]), $stored['size']);
        self::assertSame(hash('sha256', $storage->objects[$stored['object_id']]), $stored['sha256']);
        self::assertStringNotContainsString('private metadata', $storage->objects[$stored['object_id']]);
        self::assertSame('image/png', new \finfo(FILEINFO_MIME_TYPE)->buffer($storage->objects[$stored['object_id']]));
    }

    public function testRejectsSvgAndMalformedImageDataWithoutWritingToStorage(): void
    {
        $storage = new InlineImageRecordingStorage();
        $service = new ContentInlineImageUploadService($storage);

        foreach ([
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            'not an image',
        ] as $contents) {
            try {
                $service->storeContents($contents);
                self::fail('Unsafe image data must be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertSame([], $storage->objects);
            }
        }
    }

    public function testRejectsFilesOverFourMebibytes(): void
    {
        $storage = new InlineImageRecordingStorage();
        $service = new ContentInlineImageUploadService($storage);

        $this->expectException(\InvalidArgumentException::class);
        $service->storeContents(str_repeat('x', 4_194_305));
    }

    public function testUploadedFileMustBeValidAndMatchItsReportedSize(): void
    {
        $storage = new InlineImageRecordingStorage();
        $service = new ContentInlineImageUploadService($storage);
        $path = tempnam(sys_get_temp_dir(), 'inline-image-test-');
        self::assertIsString($path);
        self::assertSame(strlen($this->png()), file_put_contents($path, $this->png()));

        try {
            $upload = new UploadedFile($path, 'inline.png', 'image/png', null, true);
            $stored = $service->store($upload);
            self::assertArrayHasKey($stored['object_id'], $storage->objects);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(3, 2);
        self::assertInstanceOf(\GdImage::class, $image);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 25, 80, 140, 40);
        self::assertNotFalse($transparent);
        imagefill($image, 0, 0, $transparent);

        ob_start();
        imagepng($image);
        $contents = ob_get_clean();

        self::assertIsString($contents);
        self::assertNotSame('', $contents);

        return $contents;
    }
}

/** @internal */
final class InlineImageRecordingStorage implements PrivateObjectStorageInterface
{
    /** @var array<string, string> */
    public array $objects = [];

    public function write(string $objectId, string $contents): void
    {
        $this->objects[$objectId] = $contents;
    }

    public function read(string $objectId, int $maximumBytes): string
    {
        $contents = $this->objects[$objectId] ?? throw new \RuntimeException('Missing object.');
        if ($maximumBytes < 1 || strlen($contents) > $maximumBytes) {
            throw new PrivateStorageOperationFailed();
        }

        return $contents;
    }

    public function delete(string $objectId): void
    {
        unset($this->objects[$objectId]);
    }
}
