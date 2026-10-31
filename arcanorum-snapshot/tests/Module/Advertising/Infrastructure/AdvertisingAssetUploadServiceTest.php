<?php

declare(strict_types=1);

namespace App\Tests\Module\Advertising\Infrastructure;

use App\Module\Advertising\Infrastructure\AdvertisingAssetUploadService;
use App\Platform\Hosting\PrivateObjectStorageInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\File\UploadedFile;

#[CoversClass(AdvertisingAssetUploadService::class)]
final class AdvertisingAssetUploadServiceTest extends TestCase
{
    private ?string $temporaryFile = null;

    protected function tearDown(): void
    {
        if (null !== $this->temporaryFile && is_file($this->temporaryFile)) {
            unlink($this->temporaryFile);
        }
    }

    public function testImageUploadUsesPrivateStorageAndOpaqueSameOriginPath(): void
    {
        $contents = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC', true);
        self::assertIsString($contents);
        $upload = $this->uploadedFile($contents, 'banner.png');

        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage->expects(self::once())->method('write')->with(
            self::callback(static fn (string $objectId): bool => 1 === preg_match('/\A[a-f0-9]{64}\z/D', $objectId)),
            $contents,
        );
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('insert')->with(
            'cms_advertising_asset',
            self::callback(static fn (array $row): bool => 'image' === ($row['kind'] ?? null)
                && 'image/png' === ($row['mime_type'] ?? null)
                && is_int($row['size'] ?? null)
                && is_string($row['storage_object_id'] ?? null)
                && 1 === preg_match('/\A[a-f0-9]{64}\z/D', $row['storage_object_id'])),
        )->willReturn(1);

        $service = new AdvertisingAssetUploadService($storage, $connection, new NullLogger());
        $path = $service->storeImage($upload);

        self::assertMatchesRegularExpression('/\A\/media\/ads\/[a-f0-9]{32}\.png\z/D', $path);
    }

    public function testImageUploadRejectsMarkupDisguisedAsAnImage(): void
    {
        $upload = $this->uploadedFile('<script>not an image</script>', 'banner.png');
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $storage->expects(self::never())->method('write');
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('insert');
        $service = new AdvertisingAssetUploadService($storage, $connection, new NullLogger());

        $this->expectException(\InvalidArgumentException::class);
        $service->storeImage($upload);
    }

    private function uploadedFile(string $contents, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'arcanorum-ad-upload-');
        self::assertIsString($path);
        $this->temporaryFile = $path;
        self::assertSame(strlen($contents), file_put_contents($path, $contents));

        return new UploadedFile($path, $name, null, UPLOAD_ERR_OK, true);
    }
}
