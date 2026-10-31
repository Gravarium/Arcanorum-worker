<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AdminRole;
use App\Entity\AdminUser;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoQuality;
use App\Entity\VideoQualitySource;
use App\Entity\VideoSourceType;
use App\Module\Video\Service\VideoStreamSourceResolver;
use App\Platform\Security\AdminMfaProvisioningService;
use App\Platform\Security\AdminMfaSecretCipher;
use App\Platform\Security\RuntimeAttestationReportProviderInterface;
use App\Platform\Security\TotpVerifier;
use App\Repository\VideoQualitySourceRepository;
use App\Tests\Support\VerifiedRuntimeAttestationReportProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class VideoQualitySourceFlowTest extends WebTestCase
{
    public static function setUpBeforeClass(): void
    {
        $mfaKey = base64_encode(str_repeat('Q', 32));
        putenv('ADMIN_MFA_ENCRYPTION_KEY='.$mfaKey);
        $_ENV['ADMIN_MFA_ENCRYPTION_KEY'] = $mfaKey;
        $_SERVER['ADMIN_MFA_ENCRYPTION_KEY'] = $mfaKey;

        parent::setUpBeforeClass();
    }

    public function testAdministratorCanManageSafeQualitySourcesAndVisitorsCanSelectThem(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            VideoStreamSourceResolver::class,
            new VideoStreamSourceResolver(['media.example.org', 'cdn.example.net']),
        );
        $client->getContainer()->set(
            RuntimeAttestationReportProviderInterface::class,
            new VerifiedRuntimeAttestationReportProvider(),
        );
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $suffix = bin2hex(random_bytes(8));
        $video = new Video(
            'Video quality flow '.$suffix,
            'A public test video with multiple safe streams.',
            null,
            VideoSourceType::File,
            '',
            PublishingStatus::Published,
            true,
            false,
        );
        $video->attachUploadedMedia(bin2hex(random_bytes(32)), 'video/mp4', 1024);
        $audioVideo = new Video(
            'Audio quality flow '.$suffix,
            'Audio media does not accept video quality variants.',
            null,
            VideoSourceType::File,
            '',
            PublishingStatus::Draft,
            false,
            false,
        );
        $audioVideo->attachUploadedMedia(bin2hex(random_bytes(32)), 'audio/mpeg', 1024);
        $audioStreamVideo = new Video(
            'Audio stream quality flow '.$suffix,
            'MP3 streams do not accept video quality variants.',
            null,
            VideoSourceType::Stream,
            'https://media.example.org/audio.mp3',
            PublishingStatus::Draft,
            false,
            false,
        );
        $admin = new AdminUser('video-quality-admin-'.$suffix.'@example.test');
        $admin->setRoles([AdminRole::Administrator->value]);
        $password = 'VideoQuality-Admin-'.$suffix.'!2026';
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $admin->setPassword($hasher->hashPassword($admin, $password));
        $provisioner = $client->getContainer()->get(AdminMfaProvisioningService::class);
        self::assertInstanceOf(AdminMfaProvisioningService::class, $provisioner);
        $provisioner->provision($admin);

        try {
            $entityManager->persist($video);
            $entityManager->persist($audioVideo);
            $entityManager->persist($audioStreamVideo);
            $entityManager->persist($admin);
            $entityManager->flush();
            $editPath = '/admin/videos/'.$video->getId().'/edit';
            $sourcePath = '/admin/videos/'.$video->getId().'/quality-sources';

            $client->request('POST', $sourcePath, [
                '_token' => 'not-authenticated',
                'resolution' => '720p',
                'source_url' => 'https://cdn.example.net/720.mp4',
            ]);
            self::assertResponseRedirects('/admin/login');

            $this->submitLogin($client, $admin, $password);
            $client->request('GET', $editPath);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('section[aria-labelledby="video-quality-heading"]');
            $token = (string) $client->getCrawler()
                ->filter('form[action="'.$sourcePath.'"] input[name="_token"]')
                ->attr('value');
            self::assertNotSame('', $token);

            $client->request('POST', $sourcePath, [
                '_token' => $token,
                'resolution' => '720p',
                'source_url' => 'https://cdn.example.net/720.mp4',
            ]);
            self::assertResponseRedirects($editPath);

            $client->request('GET', '/videos/'.$video->getId());
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('video#video-player-'.$video->getId());
            self::assertSelectorExists('select[data-video-quality-selector] option[value="https://cdn.example.net/720.mp4"]');
            self::assertSelectorExists('script[src*="video-quality-selector.js"]');
            self::assertTrue($client->getResponse()->headers->getCacheControlDirective('no-store'));
            $csp = (string) $client->getResponse()->headers->get('Content-Security-Policy-Report-Only');
            self::assertStringContainsString('media-src', $csp);
            self::assertStringContainsString('https://cdn.example.net', $csp);
            self::assertFalse($client->getResponse()->headers->has('Content-Security-Policy'));

            $client->request('GET', $editPath);
            $token = (string) $client->getCrawler()
                ->filter('form[action="'.$sourcePath.'"] input[name="_token"]')
                ->attr('value');
            $client->request('POST', $sourcePath, [
                '_token' => $token,
                'resolution' => '480p',
                'source_url' => 'https://unapproved.example.net/480.mp4',
            ]);
            self::assertResponseRedirects($editPath);
            $this->assertQualityCount($client, $video, 1);

            $client->request('GET', $editPath);
            $token = (string) $client->getCrawler()
                ->filter('form[action="'.$sourcePath.'"] input[name="_token"]')
                ->attr('value');
            $client->request('POST', $sourcePath, [
                '_token' => $token,
                'resolution' => '1080p',
                'source_url' => 'https://cdn.example.net/720.mp4',
            ]);
            self::assertResponseRedirects($editPath);
            $this->assertQualityCount($client, $video, 1);

            $client->request('GET', $editPath);
            $token = (string) $client->getCrawler()
                ->filter('form[action="'.$sourcePath.'"] input[name="_token"]')
                ->attr('value');
            $client->request('POST', $sourcePath, [
                '_token' => $token,
                'resolution' => '720p',
                'source_url' => 'https://media.example.org/new-720.mp4',
            ]);
            self::assertResponseRedirects($editPath);
            $entityManager->clear();
            $repository = $client->getContainer()->get(VideoQualitySourceRepository::class);
            self::assertInstanceOf(VideoQualitySourceRepository::class, $repository);
            $storedVideo = $entityManager->getRepository(Video::class)->find($video->getId());
            self::assertInstanceOf(Video::class, $storedVideo);
            $sources = $repository->findForVideo($storedVideo);
            self::assertCount(1, $sources);
            self::assertSame(VideoQuality::P720, $sources[0]->getResolution());
            self::assertSame('https://media.example.org/new-720.mp4', $sources[0]->getSourceUrl());

            $client->request('GET', $editPath);
            $source = $sources[0];
            $deletePath = $sourcePath.'/'.$source->getId().'/delete';
            $deleteToken = (string) $client->getCrawler()
                ->filter('form[action="'.$deletePath.'"] input[name="_token"]')
                ->attr('value');
            $client->request('POST', $deletePath, ['_token' => $deleteToken, 'action' => 'delete']);
            self::assertResponseRedirects($editPath);
            $this->assertQualityCount($client, $video, 0);

            $client->request('POST', $sourcePath, [
                '_token' => 'invalid-csrf-token',
                'resolution' => '720p',
                'source_url' => 'https://cdn.example.net/720.mp4',
            ]);
            self::assertResponseStatusCodeSame(403);

            $csrfTokens = $client->getContainer()->get(CsrfTokenManagerInterface::class);
            $requestStack = $client->getContainer()->get(RequestStack::class);
            self::assertInstanceOf(CsrfTokenManagerInterface::class, $csrfTokens);
            self::assertInstanceOf(RequestStack::class, $requestStack);
            foreach ([$audioVideo, $audioStreamVideo] as $unsupportedAudio) {
                $audioEditPath = '/admin/videos/'.$unsupportedAudio->getId().'/edit';
                $audioSourcePath = '/admin/videos/'.$unsupportedAudio->getId().'/quality-sources';
                $client->request('GET', $audioEditPath);
                self::assertResponseIsSuccessful();
                self::assertSelectorNotExists('form[action="'.$audioSourcePath.'"]');
                $csrfRequest = Request::create($audioSourcePath);
                $session = $client->getRequest()->getSession();
                $csrfRequest->setSession($session);
                $requestStack->push($csrfRequest);
                try {
                    $csrfToken = $csrfTokens->getToken('admin_video_quality_source_save_'.$unsupportedAudio->getId())->getValue();
                } finally {
                    $requestStack->pop();
                }
                $session->save();
                $client->request('POST', $audioSourcePath, [
                    '_token' => $csrfToken,
                    'resolution' => '720p',
                    'source_url' => 'https://cdn.example.net/720.mp4',
                ]);
                self::assertResponseRedirects($audioEditPath);
                $this->assertQualityCount($client, $unsupportedAudio, 0);
            }

            $entityManager->clear();
            $storedVideo = $entityManager->getRepository(Video::class)->find($video->getId());
            self::assertInstanceOf(Video::class, $storedVideo);
            $legacyAudioQuality = new VideoQualitySource(
                $storedVideo,
                VideoQuality::P720,
                'https://cdn.example.net/audio.mp3',
            );
            $entityManager->persist($legacyAudioQuality);
            $entityManager->flush();
            $client->request('GET', '/videos/'.$video->getId());
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('select[data-video-quality-selector]');
            self::assertStringNotContainsString('video-quality-selector.js', (string) $client->getResponse()->getContent());
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $video->getId()]);
            $entityManager->getConnection()->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $audioVideo->getId()]);
            $entityManager->getConnection()->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $audioStreamVideo->getId()]);
            $entityManager->getConnection()->executeStatement('DELETE FROM admin_users WHERE id = :id', ['id' => $admin->getId()]);
        }
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
        self::assertResponseRedirects('/admin');
    }

    private function assertQualityCount(KernelBrowser $client, Video $video, int $expectedCount): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();
        $storedVideo = $entityManager->getRepository(Video::class)->find($video->getId());
        self::assertInstanceOf(Video::class, $storedVideo);
        $repository = $client->getContainer()->get(VideoQualitySourceRepository::class);
        self::assertInstanceOf(VideoQualitySourceRepository::class, $repository);
        self::assertCount($expectedCount, $repository->findForVideo($storedVideo));
    }
}
