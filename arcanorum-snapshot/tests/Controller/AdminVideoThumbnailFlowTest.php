<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AdminRole;
use App\Entity\AdminUser;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Platform\Security\AdminMfaProvisioningService;
use App\Platform\Security\AdminMfaSecretCipher;
use App\Platform\Security\RuntimeAttestationReportProviderInterface;
use App\Platform\Security\TotpVerifier;
use App\Service\ExternalVideoEmbedResolver;
use App\Service\VideoProviderImportService;
use App\Service\VideoThumbnailImportService;
use App\Service\VideoThumbnailUploadService;
use App\Tests\Support\VerifiedRuntimeAttestationReportProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
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
