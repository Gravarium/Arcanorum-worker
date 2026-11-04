<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AdminRole;
use App\Entity\AdminUser;
use App\Entity\ContentCategory;
use App\Entity\ContentCategoryType;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Platform\Security\AdminMfaProvisioningService;
use App\Platform\Security\AdminMfaSecretCipher;
use App\Platform\Security\RuntimeAttestationReportProviderInterface;
use App\Platform\Security\TotpVerifier;
use App\Service\ExternalVideoEmbedResolver;
use App\Service\VideoMediaUploadService;
use App\Service\VideoProviderImportService;
use App\Service\VideoSocialPreviewGenerator;
use App\Service\VideoThumbnailImportService;
use App\Service\VideoThumbnailUploadService;
use App\Tests\Support\VerifiedRuntimeAttestationReportProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminVideoThumbnailFlowTest extends WebTestCase
{
    public static function setUpBeforeClass(): void
    {
        $mfaKey = base64_encode(str_repeat('V', 32));
        putenv('ADMIN_MFA_ENCRYPTION_KEY='.$mfaKey);
        $_ENV['ADMIN_MFA_ENCRYPTION_KEY'] = $mfaKey;
        $_SERVER['ADMIN_MFA_ENCRYPTION_KEY'] = $mfaKey;

        parent::setUpBeforeClass();
    }

    public function testAdministratorCanAssignSeveralVideoCategoriesAndRejectsNewsCategories(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );

        $suffix = bin2hex(random_bytes(6));
        $email = 'multiple-video-categories-'.$suffix.'@example.test';
        $password = 'VideoCategories-'.$suffix.'-G4!';
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $firstCategory = new ContentCategory('News category A '.$suffix, ContentCategoryType::Video);
        $secondCategory = new ContentCategory('News category B '.$suffix, ContentCategoryType::Video);
        $newsCategory = new ContentCategory('Not a video category '.$suffix, ContentCategoryType::News);
        $temporaryPath = tempnam(sys_get_temp_dir(), 'arcanorum-video-thumbnail-create-');
        self::assertIsString($temporaryPath);
        $thumbnailPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/WioAAAAASUVORK5CYII=', true);
        self::assertIsString($thumbnailPng);
        self::assertSame(strlen($thumbnailPng), file_put_contents($temporaryPath, $thumbnailPng));
        $storedThumbnailObjectId = null;
        $privateStorage = $this->createMock(PrivateObjectStorageInterface::class);
        $privateStorage->expects(self::once())->method('write')->willReturnCallback(
            static function (string $objectId, string $contents) use (&$storedThumbnailObjectId, $thumbnailPng): void {
                self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $objectId);
                self::assertSame($thumbnailPng, $contents);
                $storedThumbnailObjectId = $objectId;
            },
        );
        $client->getContainer()->set(PrivateObjectStorageInterface::class, $privateStorage);
        $client->getContainer()->set(VideoThumbnailUploadService::class, new VideoThumbnailUploadService($privateStorage));
        $entityManager->persist($firstCategory);
        $entityManager->persist($secondCategory);
        $entityManager->persist($newsCategory);
        $entityManager->flush();

        try {
            $admin = $this->persistAdministrator($client, $email, $password);
            $this->submitLogin($client, $admin, $password);
            self::assertResponseRedirects('/admin');

            $newVideoPath = '/admin/videos/new';
            $client->request('GET', $newVideoPath);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('select[name="category_ids[]"][multiple][size="8"]');
            self::assertSelectorExists('select[name="category_ids[]"] option[value="'.$firstCategory->getId().'"]');

            $token = $client->getCrawler()->filter('form.admin-form input[name="_token"]')->first()->attr('value');
            self::assertIsString($token);
            $payload = [
                '_token' => $token,
                'category_ids_present' => '1',
                'category_ids' => [$firstCategory->getId(), $newsCategory->getId()],
                'title' => 'Rejected mixed category video '.$suffix,
                'slug' => 'rejected-mixed-category-'.$suffix,
                'description' => 'A category type validation check.',
                'tags' => '',
                'source_type' => 'embed',
                'source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'duration_minutes' => '',
                'duration_seconds_part' => '',
                'status' => 'draft',
                'publish_at' => '',
                'author_name' => 'Editorial Desk',
                'language_code' => '',
                'view_count' => '0',
                'embed_origins' => '',
                'custom_fields_present' => '1',
            ];
            $client->request('POST', $newVideoPath, $payload);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSelectorTextContains('[role="alert"]', 'nicht verfügbar');
            self::assertNull($entityManager->getRepository(Video::class)->findOneBy(['slug' => 'rejected-mixed-category-'.$suffix]));

            $client->request('GET', $newVideoPath);
            $validToken = $client->getCrawler()->filter('form.admin-form input[name="_token"]')->first()->attr('value');
            self::assertIsString($validToken);
            $payload['_token'] = $validToken;
            $payload['category_ids'] = [$firstCategory->getId(), $secondCategory->getId()];
            $payload['title'] = 'Multiple category video '.$suffix;
            $payload['slug'] = 'multiple-category-'.$suffix;
            $client->request(
                'POST',
                $newVideoPath,
                $payload,
                ['thumbnail_file' => new UploadedFile($temporaryPath, 'preview.png', 'image/png', null, true)],
            );
            self::assertResponseRedirects('/admin/videos');

            $entityManager->clear();
            $savedVideo = $entityManager->getRepository(Video::class)->findOneBy(['slug' => 'multiple-category-'.$suffix]);
            self::assertInstanceOf(Video::class, $savedVideo);
            self::assertSame([$firstCategory->getName(), $secondCategory->getName()], $savedVideo->getCategoryNames());
            self::assertSame($storedThumbnailObjectId, $savedVideo->getThumbnailObjectId());
        } finally {
            $entityManager->getConnection()->executeStatement(
                'DELETE FROM videos WHERE slug IN (:rejected_slug, :saved_slug)',
                ['rejected_slug' => 'rejected-mixed-category-'.$suffix, 'saved_slug' => 'multiple-category-'.$suffix],
            );
            $entityManager->getConnection()->executeStatement('DELETE FROM admin_users WHERE email = :email', ['email' => $email]);
            $entityManager->getConnection()->executeStatement(
                'DELETE FROM content_categories WHERE id IN (:first, :second, :news)',
                ['first' => $firstCategory->getId(), 'second' => $secondCategory->getId(), 'news' => $newsCategory->getId()],
            );
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    public function testAdministratorCanImportAValidatedProviderThumbnailForAnExistingVideo(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );

        $suffix = bin2hex(random_bytes(8));
        $email = 'provider-thumbnail-'.$suffix.'@example.test';
        $password = 'ProviderThumbnail-'.$suffix.'-G4!';
        $video = new Video(
            'Provider thumbnail '.$suffix,
            'A draft video used to check secure provider thumbnail import.',
            null,
            VideoSourceType::Embed,
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            PublishingStatus::Draft,
            false,
            true,
        );
        $unsupportedVideo = new Video(
            'Unsupported thumbnail source '.$suffix,
            'Stream sources cannot use provider thumbnail import.',
            null,
            VideoSourceType::Stream,
            'https://media.example.org/clip.mp4',
            PublishingStatus::Draft,
            false,
            true,
        );
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        try {
            $entityManager->persist($video);
            $entityManager->persist($unsupportedVideo);
            $entityManager->flush();

            $providerRequestCount = 0;
            $providerPayload = json_encode([
                'items' => [[
                    'id' => 'dQw4w9WgXcQ',
                    'snippet' => [
                        'title' => 'Safe provider thumbnail video',
                        'description' => 'Metadata from the fixed provider API.',
                        'thumbnails' => [
                            'medium' => ['url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/mqdefault.jpg', 'width' => 320],
                        ],
                    ],
                    'contentDetails' => ['duration' => 'PT90S'],
                    'status' => ['privacyStatus' => 'public', 'embeddable' => true],
                ]],
            ], JSON_THROW_ON_ERROR);
            $providerImport = new VideoProviderImportService(
                new MockHttpClient(static function (string $method, string $url, array $options) use (&$providerRequestCount, $providerPayload): MockResponse {
                    ++$providerRequestCount;
                    self::assertSame('GET', $method);
                    self::assertSame(0, $options['max_redirects'] ?? null);
                    $parts = parse_url($url);
                    self::assertIsArray($parts);
                    self::assertSame('www.googleapis.com', $parts['host'] ?? null);
                    self::assertSame('/youtube/v3/videos', $parts['path'] ?? null);

                    return new MockResponse($providerPayload, ['http_code' => Response::HTTP_OK]);
                }),
                new ExternalVideoEmbedResolver(),
                'test-provider-api-key',
            );
            $client->getContainer()->set(VideoProviderImportService::class, $providerImport);

            $thumbnailPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/WioAAAAASUVORK5CYII=', true);
            self::assertIsString($thumbnailPng);
            $storedObjectId = null;
            $privateStorage = $this->createMock(PrivateObjectStorageInterface::class);
            $privateStorage->expects(self::once())->method('write')->willReturnCallback(
                static function (string $objectId, string $contents) use (&$storedObjectId, $thumbnailPng): void {
                    self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $objectId);
                    self::assertSame($thumbnailPng, $contents);
                    $storedObjectId = $objectId;
                },
            );
            $client->getContainer()->set(PrivateObjectStorageInterface::class, $privateStorage);
            $client->getContainer()->set(VideoThumbnailImportService::class, new VideoThumbnailImportService(
                new MockHttpClient(static function (string $method, string $url, array $options) use ($thumbnailPng): MockResponse {
                    self::assertSame('GET', $method);
                    self::assertSame(0, $options['max_redirects'] ?? null);
                    $parts = parse_url($url);
                    self::assertIsArray($parts);
                    self::assertSame('i.ytimg.com', $parts['host'] ?? null);
                    self::assertSame('/vi/dQw4w9WgXcQ/mqdefault.jpg', $parts['path'] ?? null);

                    return new MockResponse($thumbnailPng, ['http_code' => Response::HTTP_OK]);
                }),
                new VideoThumbnailUploadService($privateStorage),
            ));

            $admin = $this->persistAdministrator($client, $email, $password);
            $importPath = '/admin/videos/'.$video->getId().'/thumbnail/provider-import';
            $editPath = '/admin/videos/'.$video->getId().'/edit';

            $client->request('POST', $importPath, ['_token' => 'not-authenticated', 'action' => 'provider_import']);
            self::assertResponseRedirects('/admin/login');

            $this->submitLogin($client, $admin, $password);
            self::assertResponseRedirects('/admin');

            $client->request('GET', $editPath);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('form[action="'.$importPath.'"]');
            $token = $client->getCrawler()
                ->filter('form[action="'.$importPath.'"] input[name="_token"]')
                ->attr('value');
            self::assertIsString($token);
            self::assertNotSame('', $token);

            $client->request('GET', '/admin/videos/'.$unsupportedVideo->getId().'/edit');
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('form[action="/admin/videos/'.$unsupportedVideo->getId().'/thumbnail/provider-import"]');
            self::assertSame(0, $providerRequestCount);

            $client->request('POST', $importPath, ['_token' => 'invalid-csrf-token', 'action' => 'provider_import']);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            self::assertSame(0, $providerRequestCount);

            $client->request('POST', $importPath, [
                '_token' => $token,
                'action' => 'provider_import',
                'source_url' => 'https://untrusted.example/thumbnail.jpg',
            ]);
            self::assertResponseRedirects($editPath);
            self::assertSame(0, $providerRequestCount);

            $client->request('POST', $importPath, ['_token' => $token, 'action' => 'provider_import']);
            self::assertResponseRedirects($editPath);
            self::assertSame(1, $providerRequestCount);
            self::assertIsString($storedObjectId);

            $client->followRedirect();
            self::assertSelectorTextContains('.flash-message.is-success', 'Provider-Vorschaubild wurde sicher übernommen.');
            $entityManager->clear();
            $storedVideo = $entityManager->getRepository(Video::class)->find($video->getId());
            self::assertInstanceOf(Video::class, $storedVideo);
            self::assertSame($storedObjectId, $storedVideo->getThumbnailObjectId());
            self::assertSame('image/png', $storedVideo->getThumbnailMimeType());
        } finally {
            foreach ([$video, $unsupportedVideo] as $videoToDelete) {
                $entityManager->getConnection()->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $videoToDelete->getId()]);
            }
            $entityManager->getConnection()->executeStatement('DELETE FROM admin_users WHERE email = :email', ['email' => $email]);
        }
    }

    public function testAdministratorCanImportAValidatedThumbnailFromPublicHttpsUrl(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );

        $suffix = bin2hex(random_bytes(8));
        $email = 'thumbnail-url-'.$suffix.'@example.test';
        $password = 'ThumbnailUrl-'.$suffix.'-G4!';
        $video = new Video(
            'Thumbnail URL '.$suffix,
            'A draft video used to verify importing a private thumbnail from a safe URL.',
            null,
            VideoSourceType::Embed,
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            PublishingStatus::Draft,
            false,
            true,
        );
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        try {
            $entityManager->persist($video);
            $entityManager->flush();

            $thumbnailPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/WioAAAAASUVORK5CYII=', true);
            self::assertIsString($thumbnailPng);
            $storedObjectId = null;
            $requestCount = 0;
            $privateStorage = $this->createMock(PrivateObjectStorageInterface::class);
            $privateStorage->expects(self::once())->method('write')->willReturnCallback(
                static function (string $objectId, string $contents) use (&$storedObjectId, $thumbnailPng): void {
                    self::assertMatchesRegularExpression(
                        '/\A[a-f0-9]{64}\z/D',
                        $objectId,
                        'thumbnail_mock_failure=object_id',
                    );
                    self::assertSame($thumbnailPng, $contents, 'thumbnail_mock_failure=image_bytes');
                    $storedObjectId = $objectId;
                },
            );
            $client->getContainer()->set(PrivateObjectStorageInterface::class, $privateStorage);
            $client->getContainer()->set(VideoThumbnailImportService::class, new VideoThumbnailImportService(
                new MockHttpClient(static function (string $method, string $url, array $options) use ($thumbnailPng, &$requestCount): MockResponse {
                    ++$requestCount;
                    self::assertSame('GET', $method);
                    self::assertSame(0, $options['max_redirects'] ?? null);
                    $headers = $options['headers'] ?? null;
                    if (!is_array($headers)) {
                        throw new \UnexpectedValueException('Thumbnail request headers must be an array.');
                    }
                    self::assertContains('Accept: image/jpeg, image/png, image/webp', $headers);
                    self::assertSame('https://images.example.test/video/cover.png', $url);

                    return new MockResponse($thumbnailPng, ['http_code' => Response::HTTP_OK]);
                }),
                new VideoThumbnailUploadService($privateStorage),
            ));

            $admin = $this->persistAdministrator($client, $email, $password);
            $importPath = '/admin/videos/'.$video->getId().'/thumbnail/import-url';
            $editPath = '/admin/videos/'.$video->getId().'/edit';
            $this->submitLogin($client, $admin, $password);
            self::assertResponseRedirects('/admin');

            $client->request('GET', $editPath);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('form[action="'.$importPath.'"] input[name="thumbnail_url"]');
            $token = $client->getCrawler()
                ->filter('form[action="'.$importPath.'"] input[name="_token"]')
                ->attr('value');
            self::assertIsString($token);
            self::assertNotSame('', $token);

            $client->request('POST', $importPath, [
                '_token' => 'invalid-csrf-token',
                'action' => 'import_url',
                'thumbnail_url' => 'https://images.example.test/video/cover.png',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            self::assertSame(0, $requestCount);

            $client->request('POST', $importPath, [
                '_token' => $token,
                'action' => 'import_url',
                'thumbnail_url' => 'http://images.example.test/video/cover.png',
            ]);
            self::assertResponseRedirects($editPath);
            self::assertSame(0, $requestCount);

            $client->request('POST', $importPath, [
                '_token' => $token,
                'action' => 'import_url',
                'thumbnail_url' => 'https://images.example.test/video/cover.png',
                'unexpected' => 'ignored-fields-must-be-rejected',
            ]);
            self::assertResponseRedirects($editPath);
            self::assertSame(0, $requestCount);

            $client->request('POST', $importPath, [
                '_token' => $token,
                'action' => 'import_url',
                'thumbnail_url' => 'https://images.example.test/video/cover.png',
            ]);
            self::assertResponseRedirects($editPath);
            self::assertSame(1, $requestCount);
            self::assertIsString($storedObjectId);

            $client->followRedirect();
            self::assertSelectorTextContains('.flash-message.is-success', 'Vorschaubild wurde sicher aus der URL übernommen.');
            $entityManager->clear();
            $storedVideo = $entityManager->getRepository(Video::class)->find($video->getId());
            self::assertInstanceOf(Video::class, $storedVideo);
            self::assertSame($storedObjectId, $storedVideo->getThumbnailObjectId());
            self::assertSame('image/png', $storedVideo->getThumbnailMimeType());
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $video->getId()]);
            $entityManager->getConnection()->executeStatement('DELETE FROM admin_users WHERE email = :email', ['email' => $email]);
        }
    }

    public function testUrlThumbnailImportDiscardsImageWhenCurrentThumbnailChangesDuringFetch(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );

        $suffix = bin2hex(random_bytes(8));
        $email = 'thumbnail-url-race-'.$suffix.'@example.test';
        $password = 'ThumbnailUrlRace-'.$suffix.'-G4!';
        $video = new Video(
            'Thumbnail URL race '.$suffix,
            'A draft video used to verify an in-flight thumbnail replacement is discarded.',
            null,
            VideoSourceType::Embed,
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            PublishingStatus::Draft,
            false,
            true,
        );
        $originalObjectId = str_repeat('a', 64);
        $concurrentObjectId = str_repeat('b', 64);
        $video->attachThumbnail($originalObjectId, 'image/png', 128);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();

        try {
            $entityManager->persist($video);
            $entityManager->flush();

            $thumbnailPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/WioAAAAASUVORK5CYII=', true);
            self::assertIsString($thumbnailPng);
            $storedObjectId = null;
            $deletedObjectIds = [];
            $privateStorage = $this->createMock(PrivateObjectStorageInterface::class);
            $privateStorage->expects(self::once())->method('write')->willReturnCallback(
                static function (string $objectId, string $contents) use (&$storedObjectId, $thumbnailPng): void {
                    self::assertSame($thumbnailPng, $contents);
                    $storedObjectId = $objectId;
                },
            );
            $privateStorage->expects(self::exactly(2))->method('delete')->willReturnCallback(
                static function (string $objectId) use (&$deletedObjectIds): void {
                    $deletedObjectIds[] = $objectId;
                },
            );
            $client->getContainer()->set(PrivateObjectStorageInterface::class, $privateStorage);
            $client->getContainer()->set(VideoThumbnailImportService::class, new VideoThumbnailImportService(
                new MockHttpClient(static function (string $method, string $url, array $options) use (
                    $thumbnailPng,
                    $connection,
                    $video,
                    $concurrentObjectId,
                ): MockResponse {
                    self::assertSame('GET', $method);
                    self::assertSame('https://images.example.test/video/cover.png', $url);
                    self::assertSame(0, $options['max_redirects'] ?? null);
                    $connection->executeStatement(
                        'UPDATE videos SET thumbnail_object_id = :object_id, thumbnail_mime_type = :mime_type, thumbnail_size = :size WHERE id = :video_id',
                        [
                            'object_id' => $concurrentObjectId,
                            'mime_type' => 'image/png',
                            'size' => strlen($thumbnailPng),
                            'video_id' => $video->getId(),
                        ],
                    );

                    return new MockResponse($thumbnailPng, ['http_code' => Response::HTTP_OK]);
                }),
                new VideoThumbnailUploadService($privateStorage),
            ));

            $admin = $this->persistAdministrator($client, $email, $password);
            $this->submitLogin($client, $admin, $password);
            self::assertResponseRedirects('/admin');

            $editPath = '/admin/videos/'.$video->getId().'/edit';
            $importPath = '/admin/videos/'.$video->getId().'/thumbnail/import-url';
            $client->request('GET', $editPath);
            self::assertResponseIsSuccessful();
            $token = $client->getCrawler()
                ->filter('form[action="'.$importPath.'"] input[name="_token"]')
                ->attr('value');
            self::assertIsString($token);

            $client->request('POST', $importPath, [
                '_token' => $token,
                'action' => 'import_url',
                'thumbnail_url' => 'https://images.example.test/video/cover.png',
            ]);
            self::assertResponseRedirects($editPath);
            self::assertIsString($storedObjectId);

            $client->followRedirect();
            self::assertSelectorTextContains('.flash-message.is-error', 'während des Imports geändert');
            $entityManager->clear();
            $storedVideo = $entityManager->getRepository(Video::class)->find($video->getId());
            self::assertInstanceOf(Video::class, $storedVideo);
            self::assertSame($concurrentObjectId, $storedVideo->getThumbnailObjectId());
            self::assertSame([
                VideoSocialPreviewGenerator::cacheObjectIdForSourceObjectId($storedObjectId),
                $storedObjectId,
            ], $deletedObjectIds);
        } finally {
            $connection->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $video->getId()]);
            $connection->executeStatement('DELETE FROM admin_users WHERE email = :email', ['email' => $email]);
        }
    }

    public function testProviderImportDiscardsThumbnailWhenVideoSourceChangesDuringFetch(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );

        $suffix = bin2hex(random_bytes(8));
        $email = 'provider-thumbnail-race-'.$suffix.'@example.test';
        $password = 'ProviderThumbnailRace-'.$suffix.'-G4!';
        $sourceUrl = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
        $changedSourceUrl = 'https://www.youtube.com/watch?v=5NV6Rdv1a3I';
        $video = new Video(
            'Provider thumbnail race '.$suffix,
            'A draft video used to check source changes during provider thumbnail import.',
            null,
            VideoSourceType::Embed,
            $sourceUrl,
            PublishingStatus::Draft,
            false,
            true,
        );
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();

        try {
            $entityManager->persist($video);
            $entityManager->flush();

            $providerPayload = json_encode([
                'items' => [[
                    'id' => 'dQw4w9WgXcQ',
                    'snippet' => [
                        'title' => 'Safe provider thumbnail video',
                        'description' => 'Metadata from the fixed provider API.',
                        'thumbnails' => [
                            'medium' => ['url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/mqdefault.jpg', 'width' => 320],
                        ],
                    ],
                    'contentDetails' => ['duration' => 'PT90S'],
                    'status' => ['privacyStatus' => 'public', 'embeddable' => true],
                ]],
            ], JSON_THROW_ON_ERROR);
            $providerRequestCount = 0;
            $providerImport = new VideoProviderImportService(
                new MockHttpClient(static function () use (&$providerRequestCount, $providerPayload, $connection, $video, $changedSourceUrl): MockResponse {
                    ++$providerRequestCount;
                    $connection->executeStatement(
                        'UPDATE videos SET source_url = :source WHERE id = :id',
                        ['source' => $changedSourceUrl, 'id' => $video->getId()],
                    );

                    return new MockResponse($providerPayload, ['http_code' => Response::HTTP_OK]);
                }),
                new ExternalVideoEmbedResolver(),
                'test-provider-api-key',
            );
            $client->getContainer()->set(VideoProviderImportService::class, $providerImport);

            $thumbnailPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/WioAAAAASUVORK5CYII=', true);
            self::assertIsString($thumbnailPng);
            $storedObjectId = null;
            $deletedObjectIds = [];
            $privateStorage = $this->createMock(PrivateObjectStorageInterface::class);
            $privateStorage->expects(self::once())->method('write')->willReturnCallback(
                static function (string $objectId, string $contents) use (&$storedObjectId, $thumbnailPng): void {
                    self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $objectId);
                    self::assertSame($thumbnailPng, $contents);
                    $storedObjectId = $objectId;
                },
            );
            $privateStorage->expects(self::exactly(2))->method('delete')->willReturnCallback(
                static function (string $objectId) use (&$deletedObjectIds): void {
                    $deletedObjectIds[] = $objectId;
                },
            );
            $client->getContainer()->set(PrivateObjectStorageInterface::class, $privateStorage);
            $client->getContainer()->set(VideoThumbnailImportService::class, new VideoThumbnailImportService(
                new MockHttpClient(static fn (): MockResponse => new MockResponse($thumbnailPng, ['http_code' => Response::HTTP_OK])),
                new VideoThumbnailUploadService($privateStorage),
            ));

            $admin = $this->persistAdministrator($client, $email, $password);
            $importPath = '/admin/videos/'.$video->getId().'/thumbnail/provider-import';
            $editPath = '/admin/videos/'.$video->getId().'/edit';
            $this->submitLogin($client, $admin, $password);
            self::assertResponseRedirects('/admin');

            $client->request('GET', $editPath);
            self::assertResponseIsSuccessful();
            $token = $client->getCrawler()
                ->filter('form[action="'.$importPath.'"] input[name="_token"]')
                ->attr('value');
            self::assertIsString($token);
            self::assertNotSame('', $token);

            $client->request('POST', $importPath, ['_token' => $token, 'action' => 'provider_import']);
            self::assertResponseRedirects($editPath);
            self::assertSame(1, $providerRequestCount);
            self::assertIsString($storedObjectId);

            $client->followRedirect();
            self::assertSelectorTextContains('.flash-message.is-error', 'Videoquelle wurde während des Imports geändert');
            $entityManager->clear();
            $storedVideo = $entityManager->getRepository(Video::class)->find($video->getId());
            self::assertInstanceOf(Video::class, $storedVideo);
            self::assertSame($changedSourceUrl, $storedVideo->getSourceUrl());
            self::assertNull($storedVideo->getThumbnailObjectId());
            self::assertSame([
                VideoSocialPreviewGenerator::cacheObjectIdForSourceObjectId($storedObjectId),
                $storedObjectId,
            ], $deletedObjectIds);
        } finally {
            $connection->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $video->getId()]);
            $connection->executeStatement('DELETE FROM admin_users WHERE email = :email', ['email' => $email]);
        }
    }

    public function testThumbnailUploadLocksCurrentVideoStateBeforeReplacingThumbnail(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );

        $suffix = bin2hex(random_bytes(8));
        $email = 'thumbnail-upload-race-'.$suffix.'@example.test';
        $password = 'ThumbnailUploadRace-'.$suffix.'-G4!';
        $video = new Video(
            'Thumbnail upload race '.$suffix,
            'A draft video used to check concurrent thumbnail replacement.',
            null,
            VideoSourceType::Url,
            'https://video.example.test/'.$suffix.'.mp4',
            PublishingStatus::Draft,
            false,
            true,
        );
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $temporaryPath = tempnam(sys_get_temp_dir(), 'arcanorum-thumbnail-race-');
        self::assertIsString($temporaryPath);
        $thumbnailPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/WioAAAAASUVORK5CYII=', true);
        self::assertIsString($thumbnailPng);
        self::assertSame(strlen($thumbnailPng), file_put_contents($temporaryPath, $thumbnailPng));
        $concurrentObjectId = str_repeat('b', 64);
        $storedObjectId = null;
        $deletedObjectIds = [];
        $privateStorage = $this->createMock(PrivateObjectStorageInterface::class);
        $privateStorage->expects(self::once())->method('write')->willReturnCallback(
            static function (string $objectId, string $contents) use (
                &$storedObjectId,
                $thumbnailPng,
                $connection,
                $video,
                $concurrentObjectId,
            ): void {
                self::assertSame($thumbnailPng, $contents);
                $storedObjectId = $objectId;
                $connection->executeStatement(
                    'UPDATE videos SET thumbnail_object_id = :object_id, thumbnail_mime_type = :mime_type, thumbnail_size = :size WHERE id = :video_id',
                    [
                        'object_id' => $concurrentObjectId,
                        'mime_type' => 'image/png',
                        'size' => strlen($thumbnailPng),
                        'video_id' => $video->getId(),
                    ],
                );
            },
        );
        $privateStorage->expects(self::exactly(2))->method('delete')->willReturnCallback(
            static function (string $objectId) use (&$deletedObjectIds): void {
                $deletedObjectIds[] = $objectId;
            },
        );
        $client->getContainer()->set(VideoThumbnailUploadService::class, new VideoThumbnailUploadService($privateStorage));
        $entityManager->persist($video);
        $entityManager->flush();

        try {
            $admin = $this->persistAdministrator($client, $email, $password);
            $this->submitLogin($client, $admin, $password);
            self::assertResponseRedirects('/admin');

            $editPath = '/admin/videos/'.$video->getId().'/edit';
            $uploadPath = '/admin/videos/'.$video->getId().'/thumbnail';
            $client->request('GET', $editPath);
            self::assertResponseIsSuccessful();
            $token = $client->getCrawler()
                ->filter('form[action="'.$uploadPath.'"] input[name="_token"]')
                ->eq(0)
                ->attr('value');
            self::assertIsString($token);

            $client->request(
                'POST',
                $uploadPath,
                ['_token' => $token, 'action' => 'upload'],
                ['thumbnail_file' => new UploadedFile($temporaryPath, 'thumbnail.png', 'image/png', null, true)],
            );
            self::assertResponseRedirects($editPath);
            self::assertIsString($storedObjectId);

            $entityManager->clear();
            $storedVideo = $entityManager->getRepository(Video::class)->find($video->getId());
            self::assertInstanceOf(Video::class, $storedVideo);
            self::assertSame($storedObjectId, $storedVideo->getThumbnailObjectId());
            self::assertSame(
                [
                    VideoSocialPreviewGenerator::cacheObjectIdForSourceObjectId($concurrentObjectId),
                    $concurrentObjectId,
                ],
                $deletedObjectIds,
            );
        } finally {
            $connection->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $video->getId()]);
            $connection->executeStatement('DELETE FROM admin_users WHERE email = :email', ['email' => $email]);
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    public function testAdminVideoEditLocksCurrentPrivateMediaBeforeReplacement(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );

        $suffix = bin2hex(random_bytes(8));
        $email = 'video-media-edit-race-'.$suffix.'@example.test';
        $password = 'VideoMediaEditRace-'.$suffix.'-G4!';
        $video = new Video(
            'Private media edit race '.$suffix,
            'A video used to check concurrent private media replacement.',
            null,
            VideoSourceType::File,
            '',
            PublishingStatus::Draft,
            false,
            true,
        );
        $originalObjectId = str_repeat('a', 64);
        $video->attachUploadedMedia($originalObjectId, 'video/mp4', 128);

        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $temporaryPath = tempnam(sys_get_temp_dir(), 'arcanorum-video-media-race-');
        self::assertIsString($temporaryPath);
        $videoBytes = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64);
        self::assertSame(strlen($videoBytes), file_put_contents($temporaryPath, $videoBytes));
        $concurrentObjectId = str_repeat('b', 64);
        $storedObjectId = null;
        $privateStorage = $this->createMock(PrivateObjectStorageInterface::class);
        $privateStorage->expects(self::once())->method('write')->willReturnCallback(
            static function (string $objectId, string $contents) use (
                &$storedObjectId,
                $videoBytes,
                $connection,
                $video,
                $concurrentObjectId,
            ): void {
                self::assertSame($videoBytes, $contents);
                $storedObjectId = $objectId;
                $connection->executeStatement(
                    'UPDATE videos SET uploaded_object_id = :object_id, uploaded_mime_type = :mime_type, uploaded_size = :size WHERE id = :video_id',
                    [
                        'object_id' => $concurrentObjectId,
                        'mime_type' => 'video/mp4',
                        'size' => strlen($videoBytes),
                        'video_id' => $video->getId(),
                    ],
                );
            },
        );
        $privateStorage->expects(self::once())->method('delete')->with($concurrentObjectId);
        $client->getContainer()->set(PrivateObjectStorageInterface::class, $privateStorage);
        $client->getContainer()->set(VideoMediaUploadService::class, new VideoMediaUploadService($privateStorage));
        $entityManager->persist($video);
        $entityManager->flush();

        try {
            $admin = $this->persistAdministrator($client, $email, $password);
            $this->submitLogin($client, $admin, $password);
            self::assertResponseRedirects('/admin');

            $editPath = '/admin/videos/'.$video->getId().'/edit';
            $client->request('GET', $editPath);
            self::assertResponseIsSuccessful();
            $token = $client->getCrawler()->filter('form.admin-form input[name="_token"]')->attr('value');
            self::assertIsString($token);

            $client->request(
                'POST',
                $editPath,
                [
                    '_token' => $token,
                    'title' => 'Updated private media '.$suffix,
                    'description' => 'Replace the upload after another request changed its metadata.',
                    'category' => '',
                    'tags' => '',
                    'source_type' => 'file',
                    'source_url' => '',
                    'duration_seconds' => '',
                    'status' => 'draft',
                    'comments_enabled' => '1',
                ],
                ['video_file' => new UploadedFile($temporaryPath, 'replacement.mp4', 'video/mp4', null, true)],
            );

            self::assertResponseRedirects('/admin/videos');
            self::assertIsString($storedObjectId);
            self::assertNotSame($originalObjectId, $storedObjectId);
            $entityManager->clear();
            $storedVideo = $entityManager->getRepository(Video::class)->find($video->getId());
            self::assertInstanceOf(Video::class, $storedVideo);
            self::assertSame($storedObjectId, $storedVideo->getUploadedObjectId());
            self::assertSame('video/mp4', $storedVideo->getUploadedMimeType());
            self::assertSame(strlen($videoBytes), $storedVideo->getUploadedSize());
        } finally {
            $connection->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $video->getId()]);
            $connection->executeStatement('DELETE FROM admin_users WHERE email = :email', ['email' => $email]);
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    private function persistAdministrator(KernelBrowser $client, string $email, string $password): AdminUser
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $admin = new AdminUser($email);
        $admin->setRoles([AdminRole::Administrator->value]);
        $admin->setPassword($hasher->hashPassword($admin, $password));
        $provisioner = $client->getContainer()->get(AdminMfaProvisioningService::class);
        self::assertInstanceOf(AdminMfaProvisioningService::class, $provisioner);
        $provisioner->provision($admin);

        return $admin;
    }

    private function submitLogin(KernelBrowser $client, AdminUser $admin, string $password): void
    {
        $encryptedSecret = $admin->getMfaSecretCiphertext();
        self::assertNotNull($encryptedSecret);
        $cipher = $client->getContainer()->get(AdminMfaSecretCipher::class);
        self::assertInstanceOf(AdminMfaSecretCipher::class, $cipher);
        $totp = $client->getContainer()->get(TotpVerifier::class);
        self::assertInstanceOf(TotpVerifier::class, $totp);

        $client->request('GET', '/admin/login');
        $client->submitForm('Anmelden', [
            '_username' => $admin->getUserIdentifier(),
            '_password' => $password,
            '_mfa_code' => $totp->codeAtTime($cipher->decrypt($encryptedSecret), time()),
        ]);
    }
}
