<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AdminRole;
use App\Entity\AdminUser;
use App\Entity\ContentCategory;
use App\Entity\ContentCategoryType;
use App\Entity\ContentInlineImage;
use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use App\Platform\Security\AdminMfaProvisioningService;
use App\Platform\Security\AdminMfaSecretCipher;
use App\Platform\Security\RuntimeAttestationReportProviderInterface;
use App\Platform\Security\TotpVerifier;
use App\Service\ContentInlineImageManager;
use App\Tests\Support\VerifiedRuntimeAttestationReportProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ContentInlineImageFlowTest extends WebTestCase
{
    public static function setUpBeforeClass(): void
    {
        $mfaKey = base64_encode(str_repeat('C', 32));
        putenv('ADMIN_MFA_ENCRYPTION_KEY='.$mfaKey);
        $_ENV['ADMIN_MFA_ENCRYPTION_KEY'] = $mfaKey;
        $_SERVER['ADMIN_MFA_ENCRYPTION_KEY'] = $mfaKey;

        parent::setUpBeforeClass();
    }

    public function testPublicCategoryRichTextIsSanitizedAndOnlyOwnReferencedImagesAreOutput(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $storage = new CategoryInlineImageFlowStorage();
        $client->getContainer()->set(PrivateObjectStorageInterface::class, $storage);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(6));
        $news = new ContentCategory('Rich news '.$suffix, ContentCategoryType::News);
        $video = new ContentCategory('Rich video '.$suffix, ContentCategoryType::Video);
        $newsId = bin2hex(random_bytes(32));
        $videoId = bin2hex(random_bytes(32));
        $unreferencedVideoId = bin2hex(random_bytes(32));
        $news->updateTaxonomy(
            'rich-news-'.$suffix,
            '<p onclick="alert(1)">News <strong>formatted</strong>'
            .'<img src="/content-inline-images/'.$newsId.'" alt="News image" onerror="alert(2)">'
            .'<img src="/content-inline-images/'.$unreferencedVideoId.'" alt="Foreign image"></p><script>alert(3)</script>',
            null,
            null,
            null,
            0,
        );
        $video->updateTaxonomy(
            'rich-video-'.$suffix,
            '<p>Video <img src="/content-inline-images/'.$videoId.'" alt="Video image"></p>',
            null,
            null,
            null,
            0,
        );
        $png = $this->png();
        $images = [
            new ContentInlineImage($newsId, null, null, 'image/png', strlen($png), hash('sha256', $png), $news),
            new ContentInlineImage($videoId, null, null, 'image/png', strlen($png), hash('sha256', $png), $video),
            new ContentInlineImage($unreferencedVideoId, null, null, 'image/png', strlen($png), hash('sha256', $png), $video),
        ];
        try {
            $entityManager->persist($news);
            $entityManager->persist($video);
            foreach ($images as $image) {
                $entityManager->persist($image);
                $storage->write($image->getId(), $png);
            }
            $entityManager->flush();

            $client->request('GET', '/content-inline-images/'.$newsId);
            self::assertResponseIsSuccessful();
            self::assertSame($png, $client->getResponse()->getContent());
            self::assertCacheControlHasDirectives($client->getResponse()->headers->get('Cache-Control'), 'public', 'max-age=300');
            self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));

            $client->request('GET', '/news/category/'.$news->getSlug());
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.category-description strong', 'formatted');
            self::assertSame(1, $client->getCrawler()->filter('.category-description img')->count());
            self::assertSame('/content-inline-images/'.$newsId, $client->getCrawler()->filter('.category-description img')->attr('src'));
            $categoryDescription = $client->getCrawler()->filter('.category-description');
            self::assertSame(0, $categoryDescription->filter('script, [onclick], [onerror]')->count());
            $metaDescription = $client->getCrawler()->filter('meta[name="description"]')->attr('content');
            $openGraphDescription = $client->getCrawler()->filter('meta[property="og:description"]')->attr('content');
            self::assertIsString($metaDescription);
            self::assertIsString($openGraphDescription);
            self::assertStringNotContainsString('alert(', $metaDescription);
            self::assertStringNotContainsString('alert(', $openGraphDescription);
            self::assertStringNotContainsString($unreferencedVideoId, $client->getResponse()->getContent());

            $client->request('GET', '/videos/category/'.$video->getSlug());
            self::assertResponseIsSuccessful();
            self::assertSame(1, $client->getCrawler()->filter('.category-description img')->count());
            self::assertSame('/content-inline-images/'.$videoId, $client->getCrawler()->filter('.category-description img')->attr('src'));

            // The News category's raw description references this Video-owned image, but that reference is not rendered and grants no access.
            $client->request('GET', '/content-inline-images/'.$unreferencedVideoId);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM content_categories WHERE id IN (:news, :video)', [
                'news' => $news->getId(),
                'video' => $video->getId(),
            ]);
        }
    }

    public function testNewsEditorCanUploadOnlyNewsCategoryImagesAndUnusedImagesAreQueuedForDeletion(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $storage = new CategoryInlineImageFlowStorage();
        $client->getContainer()->set(PrivateObjectStorageInterface::class, $storage);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $suffix = bin2hex(random_bytes(6));
        $news = new ContentCategory('Editor news '.$suffix, ContentCategoryType::News);
        $news->updateTaxonomy('editor-news-'.$suffix, '', null, null, null, 0);
        $video = new ContentCategory('Editor video '.$suffix, ContentCategoryType::Video);
        $video->updateTaxonomy('editor-video-'.$suffix, '', null, null, null, 0);
        [$editor, $password] = $this->staff($client, [AdminRole::ContentEditor->value]);
        $uploadedImageId = null;

        try {
            $entityManager->persist($news);
            $entityManager->persist($video);
            $entityManager->persist($editor);
            $entityManager->flush();
            $this->login($client, $editor, $password);

            $client->request('GET', '/admin/categories/new?type=news');
            self::assertResponseIsSuccessful();
            self::assertSame('', $client->getCrawler()->filter('[data-rich-text-editor]')->attr('data-rich-text-image-upload-url'));
            $newCategoryToken = (string) $client->getCrawler()->filter('form.admin-form input[name="_token"]')->first()->attr('value');
            $unownedImageId = bin2hex(random_bytes(32));
            $client->request('POST', '/admin/categories/new', [
                '_token' => $newCategoryToken,
                'name' => 'New category '.$suffix,
                'type' => 'news',
                'slug' => 'new-category-'.$suffix,
                'description' => '<p><img src="/content-inline-images/'.$unownedImageId.'" alt="unowned"></p>',
                'sort_order' => '0',
                'parent_id' => '',
                'featured' => '0',
                'meta_title' => '',
                'meta_description' => '',
                'meta_keywords' => '',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSame(0, $this->integer($entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM content_categories WHERE slug = :slug',
                ['slug' => 'new-category-'.$suffix],
            )));

            $client->request('GET', '/admin/categories/'.$news->getId().'/edit');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('textarea[name="description"][data-rich-text-source][maxlength="2000"]');
            self::assertNull($client->getCrawler()->filter('textarea[name="description"]')->attr('required'));
            $widget = $client->getCrawler()->filter('[data-rich-text-editor]');
            $uploadUrl = (string) $widget->attr('data-rich-text-image-upload-url');
            $uploadToken = (string) $widget->attr('data-rich-text-image-upload-token');
            self::assertSame('/admin/content-inline-images/news-category/'.$news->getId(), $uploadUrl);
            self::assertNotSame('', $uploadToken);

            $this->postUpload($client, $uploadUrl, 'wrong-token');
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

            $client->request('GET', '/admin/categories/'.$video->getId().'/edit');
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            $this->postUpload($client, '/admin/content-inline-images/video-category/'.$video->getId(), 'unused-token');
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            self::assertSame(0, $this->integer($entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM content_inline_images WHERE category_id = :id',
                ['id' => $video->getId()],
            )));

            $this->postUpload($client, $uploadUrl, $uploadToken);
            self::assertResponseIsSuccessful();
            $uploadResult = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($uploadResult);
            $imageUrl = $uploadResult['url'] ?? null;
            self::assertIsString($imageUrl);
            self::assertMatchesRegularExpression('~\A/content-inline-images/[a-f0-9]{64}\z~', $imageUrl);
            $imageId = substr($imageUrl, strlen('/content-inline-images/'));
            $uploadedImageId = $imageId;
            $previewUrl = $uploadResult['preview_url'] ?? null;
            self::assertIsString($previewUrl);
            self::assertSame('/admin/content-inline-images/news-category/'.$news->getId().'/'.$imageId, $previewUrl);
            self::assertSame($news->getId(), $entityManager->getConnection()->fetchOne(
                'SELECT category_id FROM content_inline_images WHERE id = :id',
                ['id' => $imageId],
            ));

            // Unpublished images are previewable only through the authenticated admin route.
            $client->request('GET', $previewUrl);
            self::assertResponseIsSuccessful();
            self::assertSame($storage->objects[$imageId], $client->getResponse()->getContent());
            self::assertCacheControlHasDirectives($client->getResponse()->headers->get('Cache-Control'), 'private', 'no-store');
            $client->request('GET', '/admin/content-inline-images/news-category/'.bin2hex(random_bytes(16)).'/'.$imageId);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

            // The public URL stays private until the category description references the image.
            $client->request('GET', $imageUrl);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
            $client->request('GET', '/admin/content-inline-images/video-category/'.$video->getId().'/'.$imageId);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

            $client->request('GET', '/admin/categories/'.$news->getId().'/edit');
            $saveToken = (string) $client->getCrawler()->filter('form.admin-form input[name="_token"]')->first()->attr('value');
            $description = '<p onclick="alert(1)">News <strong>format</strong>'
                .'<img src="'.$imageUrl.'" alt="Safe image" onerror="alert(2)"></p><script>alert(3)</script>';
            $client->request('POST', '/admin/categories/'.$news->getId().'/edit', $this->categoryFields($news, $saveToken, $description));
            self::assertResponseRedirects('/admin/categories?type=news');
            $storedDescription = $entityManager->getConnection()->fetchOne(
                'SELECT description FROM content_categories WHERE id = :id',
                ['id' => $news->getId()],
            );
            self::assertIsString($storedDescription);
            self::assertStringNotContainsString('<script', $storedDescription);
            self::assertStringNotContainsString('onclick', $storedDescription);
            self::assertStringNotContainsString('onerror', $storedDescription);
            self::assertStringContainsString('<img src="'.$imageUrl.'"', $storedDescription);

            $client->request('GET', $imageUrl);
            self::assertResponseIsSuccessful();
            $cacheControl = $client->getResponse()->headers->get('Cache-Control');
            self::assertIsString($cacheControl);
            self::assertCacheControlHasDirectives($cacheControl, 'public', 'max-age=300');
            self::assertStringNotContainsString('private', $cacheControl);

            $client->request('GET', '/admin/categories/'.$news->getId().'/edit');
            $saveToken = (string) $client->getCrawler()->filter('form.admin-form input[name="_token"]')->first()->attr('value');
            $malformedFields = $this->categoryFields($news, $saveToken, '');
            $malformedFields['description'] = ['not', 'a', 'scalar'];
            $client->request('POST', '/admin/categories/'.$news->getId().'/edit', $malformedFields);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSame($storedDescription, $entityManager->getConnection()->fetchOne(
                'SELECT description FROM content_categories WHERE id = :id',
                ['id' => $news->getId()],
            ));

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $foreignCategory = new ContentCategory('Foreign news '.$suffix, ContentCategoryType::News);
            $foreignCategory->updateTaxonomy('foreign-news-'.$suffix, '', null, null, null, 0);
            $foreignId = bin2hex(random_bytes(32));
            $foreignImage = new ContentInlineImage($foreignId, null, null, 'image/png', 128, str_repeat('f', 64), $foreignCategory);
            $entityManager->persist($foreignCategory);
            $entityManager->persist($foreignImage);
            $entityManager->flush();

            $client->request('GET', '/admin/categories/'.$news->getId().'/edit');
            $saveToken = (string) $client->getCrawler()->filter('form.admin-form input[name="_token"]')->first()->attr('value');
            $client->request(
                'POST',
                '/admin/categories/'.$news->getId().'/edit',
                $this->categoryFields($news, $saveToken, '<p><img src="/content-inline-images/'.$foreignId.'" alt="foreign"></p>'),
            );
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSame($storedDescription, $entityManager->getConnection()->fetchOne(
                'SELECT description FROM content_categories WHERE id = :id',
                ['id' => $news->getId()],
            ));

            $client->request('GET', '/admin/categories/'.$news->getId().'/edit');
            $saveToken = (string) $client->getCrawler()->filter('form.admin-form input[name="_token"]')->first()->attr('value');
            $client->request('POST', '/admin/categories/'.$news->getId().'/edit', $this->categoryFields($news, $saveToken, '<p>Updated description</p>'));
            self::assertResponseRedirects('/admin/categories?type=news');
            self::assertSame(0, $this->integer($entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM content_inline_images WHERE id = :id',
                ['id' => $imageId],
            )));
            self::assertSame(1, $this->integer($entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM private_object_deletion_jobs WHERE object_id = :id',
                ['id' => $imageId],
            )));
            $client->request('GET', $imageUrl);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        } finally {
            static::ensureKernelShutdown();
            $cleanupClient = static::createClient();
            $cleanupEntityManager = $cleanupClient->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $cleanupEntityManager);
            $connection = $cleanupEntityManager->getConnection();
            $connection->executeStatement('DELETE FROM content_categories WHERE id IN (:news, :video)', [
                'news' => $news->getId(),
                'video' => $video->getId(),
            ]);
            $connection->executeStatement('DELETE FROM content_categories WHERE slug = :slug', ['slug' => 'foreign-news-'.$suffix]);
            if (is_string($uploadedImageId)) {
                $connection->executeStatement('DELETE FROM private_object_deletion_jobs WHERE object_id = :id', ['id' => $uploadedImageId]);
            }
            foreach (array_keys($storage->objects) as $objectId) {
                $storage->delete($objectId);
            }
            $connection->delete('admin_activity_entries', ['actor_id' => $editor->getId()]);
            $connection->delete('admin_user_recovery_codes', ['admin_user_id' => $editor->getId()]);
            $connection->delete('admin_users', ['id' => $editor->getId()]);
            static::ensureKernelShutdown();
        }
    }

    public function testCategoryImageCountAndByteQuotasRejectUploadsBeforeStorageWrite(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $storage = new CategoryInlineImageFlowStorage();
        $client->getContainer()->set(PrivateObjectStorageInterface::class, $storage);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $suffix = bin2hex(random_bytes(6));
        $category = new ContentCategory('Quota news '.$suffix, ContentCategoryType::News);
        $category->updateTaxonomy('quota-news-'.$suffix, '', null, null, null, 0);
        $videoCategory = new ContentCategory('Quota video '.$suffix, ContentCategoryType::Video);
        $videoCategory->updateTaxonomy('quota-video-'.$suffix, '', null, null, null, 0);
        $categoryId = $category->getId();
        $videoCategoryId = $videoCategory->getId();
        [$editor, $password] = $this->staff($client, [AdminRole::Administrator->value]);
        try {
            $entityManager->persist($category);
            $entityManager->persist($videoCategory);
            $entityManager->persist($editor);
            $entityManager->flush();
            $this->login($client, $editor, $password);
            $client->request('GET', '/admin/categories/'.$category->getId().'/edit');
            self::assertResponseIsSuccessful();
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $category = $entityManager->getRepository(ContentCategory::class)->find($categoryId);
            $videoCategory = $entityManager->getRepository(ContentCategory::class)->find($videoCategoryId);
            self::assertInstanceOf(ContentCategory::class, $category);
            self::assertInstanceOf(ContentCategory::class, $videoCategory);
            $widget = $client->getCrawler()->filter('[data-rich-text-editor]');
            $uploadUrl = (string) $widget->attr('data-rich-text-image-upload-url');
            $uploadToken = (string) $widget->attr('data-rich-text-image-upload-token');

            for ($index = 0; $index < 20; ++$index) {
                $entityManager->getConnection()->insert('content_inline_images', [
                    'id' => bin2hex(random_bytes(32)),
                    'news_article_id' => null,
                    'static_page_id' => null,
                    'category_id' => $categoryId,
                    'mime_type' => 'image/png',
                    'size' => 1,
                    'sha256' => str_repeat('a', 64),
                    'created_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
                ]);
            }
            $this->postUpload($client, $uploadUrl, $uploadToken);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSame([], $storage->objects);

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $entityManager->getConnection()->executeStatement('DELETE FROM content_inline_images WHERE category_id = :id', ['id' => $categoryId]);
            for ($index = 0; $index < 5; ++$index) {
                $entityManager->getConnection()->insert('content_inline_images', [
                    'id' => bin2hex(random_bytes(32)),
                    'news_article_id' => null,
                    'static_page_id' => null,
                    'category_id' => $categoryId,
                    'mime_type' => 'image/jpeg',
                    'size' => 4_194_304,
                    'sha256' => str_repeat('b', 64),
                    'created_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
                ]);
            }
            $this->postUpload($client, $uploadUrl, $uploadToken);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSame([], $storage->objects);

            $this->postUpload($client, '/admin/content-inline-images/news-category/'.$videoCategoryId, 'unused-token');
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            self::assertSame([], $storage->objects);

            $manager = $client->getContainer()->get(ContentInlineImageManager::class);
            self::assertInstanceOf(ContentInlineImageManager::class, $manager);
            $invalidOwnerUpload = $this->uploadedPng();
            $invalidOwnerPath = $invalidOwnerUpload->getPathname();
            try {
                $manager->uploadForCategory($videoCategoryId, ContentCategoryType::News, $invalidOwnerUpload);
                self::fail('A News image must not be attached to a Video category.');
            } catch (NotFoundHttpException) {
                self::assertSame([], $storage->objects);
            } finally {
                if (is_file($invalidOwnerPath)) {
                    unlink($invalidOwnerPath);
                }
            }

            $client->request('GET', '/admin/categories/'.$videoCategory->getId().'/edit');
            self::assertResponseIsSuccessful();
            $videoWidget = $client->getCrawler()->filter('[data-rich-text-editor]');
            $videoUploadUrl = (string) $videoWidget->attr('data-rich-text-image-upload-url');
            $videoUploadToken = (string) $videoWidget->attr('data-rich-text-image-upload-token');
            self::assertSame('/admin/content-inline-images/video-category/'.$videoCategory->getId(), $videoUploadUrl);
            $this->postUpload($client, $videoUploadUrl, $videoUploadToken);
            self::assertResponseIsSuccessful();
            $videoUploadResult = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($videoUploadResult);
            $videoImageUrl = $videoUploadResult['url'] ?? null;
            $videoPreviewUrl = $videoUploadResult['preview_url'] ?? null;
            self::assertIsString($videoImageUrl);
            self::assertIsString($videoPreviewUrl);
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            self::assertSame(1, $this->integer($entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM content_inline_images WHERE category_id = :id',
                ['id' => $videoCategory->getId()],
            )));
            self::assertSame(
                '/admin/content-inline-images/video-category/'.$videoCategory->getId().'/'.substr($videoImageUrl, strlen('/content-inline-images/')),
                $videoPreviewUrl,
            );
            $client->request('GET', $videoPreviewUrl);
            self::assertResponseIsSuccessful();
            self::assertCacheControlHasDirectives($client->getResponse()->headers->get('Cache-Control'), 'private', 'no-store');
        } finally {
            static::ensureKernelShutdown();
            $cleanupClient = static::createClient();
            $cleanupEntityManager = $cleanupClient->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $cleanupEntityManager);
            $connection = $cleanupEntityManager->getConnection();
            $connection->executeStatement('DELETE FROM content_categories WHERE id = :id', ['id' => $categoryId]);
            $connection->executeStatement('DELETE FROM content_categories WHERE id = :id', ['id' => $videoCategoryId]);
            $connection->delete('admin_activity_entries', ['actor_id' => $editor->getId()]);
            $connection->delete('admin_user_recovery_codes', ['admin_user_id' => $editor->getId()]);
            $connection->delete('admin_users', ['id' => $editor->getId()]);
            foreach (array_keys($storage->objects) as $objectId) {
                $storage->delete($objectId);
            }
            static::ensureKernelShutdown();
        }
    }

    /** @param list<string> $roles
     * @return array{AdminUser, string}
     */
    private function staff(KernelBrowser $client, array $roles): array
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $user = new AdminUser('rich-text-'.bin2hex(random_bytes(6)).'@example.test');
        $user->setRoles($roles);
        $password = 'RichText-Editor-'.bin2hex(random_bytes(6)).'!2026';
        $user->setPassword($hasher->hashPassword($user, $password));
        $provisioner = $client->getContainer()->get(AdminMfaProvisioningService::class);
        self::assertInstanceOf(AdminMfaProvisioningService::class, $provisioner);
        $provisioner->provision($user);

        return [$user, $password];
    }

    private function login(KernelBrowser $client, AdminUser $user, string $password): void
    {
        $encryptedSecret = $user->getMfaSecretCiphertext();
        self::assertNotNull($encryptedSecret);
        $cipher = $client->getContainer()->get(AdminMfaSecretCipher::class);
        $totp = $client->getContainer()->get(TotpVerifier::class);
        self::assertInstanceOf(AdminMfaSecretCipher::class, $cipher);
        self::assertInstanceOf(TotpVerifier::class, $totp);

        $client->request('GET', '/admin/login');
        $client->submitForm('Anmelden', [
            '_username' => $user->getUserIdentifier(),
            '_password' => $password,
            '_mfa_code' => $totp->codeAtTime($cipher->decrypt($encryptedSecret), time()),
        ]);
        self::assertResponseRedirects('/admin');
        $client->followRedirect();
    }

    private function postUpload(KernelBrowser $client, string $url, string $token): void
    {
        $upload = $this->uploadedPng();
        $pathname = $upload->getPathname();
        try {
            $client->request('POST', $url, ['_token' => $token], ['image' => $upload], ['HTTP_ACCEPT' => 'application/json']);
        } finally {
            if (is_file($pathname)) {
                unlink($pathname);
            }
        }
    }

    /** @return array<string, mixed> */
    private function categoryFields(ContentCategory $category, string $token, string $description): array
    {
        return [
            '_token' => $token,
            'name' => $category->getName(),
            'slug' => $category->getSlug(),
            'description' => $description,
            'meta_title' => '',
            'meta_description' => '',
            'meta_keywords' => '',
            'sort_order' => '0',
            'parent_id' => '',
            'featured' => '0',
        ];
    }

    private function uploadedPng(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'category-inline-image-');
        self::assertIsString($path);
        $png = $this->png();
        self::assertSame(strlen($png), file_put_contents($path, $png));

        return new UploadedFile($path, 'category.png', 'image/png', null, true);
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(3, 2);
        self::assertInstanceOf(\GdImage::class, $image);
        ob_start();
        imagepng($image);
        $contents = ob_get_clean();
        self::assertIsString($contents);

        return $contents;
    }

    private function integer(mixed $value): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        self::assertIsInt($integer);

        return $integer;
    }

    private static function assertCacheControlHasDirectives(?string $cacheControl, string ...$expected): void
    {
        self::assertNotNull($cacheControl);
        $directives = array_map(
            static fn (string $directive): string => strtolower(trim($directive)),
            explode(',', $cacheControl),
        );

        foreach ($expected as $directive) {
            self::assertContains(strtolower($directive), $directives);
        }
    }
}

/** @internal */
final class CategoryInlineImageFlowStorage implements PrivateObjectStorageInterface
{
    /** @var array<string, string> */
    public array $objects = [];

    public function write(string $objectId, string $contents): void
    {
        $this->objects[$objectId] = $contents;
    }

    public function read(string $objectId, int $maximumBytes): string
    {
        $contents = $this->objects[$objectId] ?? throw new PrivateStorageOperationFailed();
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
