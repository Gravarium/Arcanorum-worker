<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Entity\VideoSubmission;
use App\Entity\VideoSubmissionStatus;
use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Service\SiteSettingsManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class PublicVideoSubmissionFlowTest extends WebTestCase
{
    public function testDisabledVideoSubmissionRouteIsPrivateNotFoundAndKeepsQueuedItems(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $settings = $client->getContainer()->get(SiteSettingsManager::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(SiteSettingsManager::class, $settings);
        $settingsProvider = $client->getContainer()->get(\App\Service\SiteSettingsProvider::class);
        self::assertInstanceOf(\App\Service\SiteSettingsProvider::class, $settingsProvider);
        $currentSettings = $settingsProvider->current();
        $originalOptions = [
            'player_autoplay_enabled' => $currentSettings->isPlayerAutoplayEnabled() ? '1' : '0',
            'player_autoplay_muted' => $currentSettings->isPlayerAutoplayMuted() ? '1' : '0',
            'default_comments_enabled' => $currentSettings->isDefaultCommentsEnabled() ? '1' : '0',
            'public_video_submissions_enabled' => $currentSettings->isPublicVideoSubmissionsEnabled() ? '1' : '0',
        ];
        $title = 'Retained pending video '.bin2hex(random_bytes(8));
        $video = new Video(
            $title,
            'A queued submission retained while public submissions are paused.',
            null,
            VideoSourceType::Stream,
            'https://media.example.org/pending.mp4',
            PublishingStatus::Draft,
            false,
            false,
        );
        $submission = new VideoSubmission($video);

        try {
            $entityManager->persist($video);
            $entityManager->persist($submission);
            $entityManager->flush();
            self::assertSame([], $settings->updateContentOptions([
                'player_autoplay_enabled' => '0',
                'player_autoplay_muted' => '1',
                'default_comments_enabled' => '1',
                'public_video_submissions_enabled' => '0',
            ]));

            $client->request('GET', '/video-submissions');
            self::assertResponseStatusCodeSame(404);
            self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));

            $client->request('POST', '/video-submissions', [
                '_token' => 'invalid-token',
                'title' => 'Blocked before validation',
            ]);
            self::assertResponseStatusCodeSame(404);

            $client->request('GET', '/videos');
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('a[href="/video-submissions"]');

            $retainedSubmission = $entityManager->getRepository(VideoSubmission::class)->find($submission->getId());
            self::assertInstanceOf(VideoSubmission::class, $retainedSubmission);
            self::assertSame(VideoSubmissionStatus::Pending, $retainedSubmission->getStatus());
        } finally {
            $storedSubmission = $entityManager->getRepository(VideoSubmission::class)->find($submission->getId());
            if ($storedSubmission instanceof VideoSubmission) {
                $entityManager->remove($storedSubmission);
            }
            $storedVideo = $entityManager->getRepository(Video::class)->find($video->getId());
            if ($storedVideo instanceof Video) {
                $entityManager->remove($storedVideo);
            }
            $settings->updateContentOptions($originalOptions);
            $entityManager->flush();
        }
    }

    public function testSubmittedVideoStaysPrivateUntilModerated(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $storage = $this->createMock(PrivateObjectStorageInterface::class);
        $videoBytes = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64);
        $storage
            ->expects($this->once())
            ->method('write')
            ->with(
                self::callback(static fn (string $objectId): bool => 1 === preg_match('/\A[a-f0-9]{64}\z/', $objectId)),
                $videoBytes,
            );
        $client->getContainer()->set(PrivateObjectStorageInterface::class, $storage);

        $path = tempnam(sys_get_temp_dir(), 'arcanorum-public-video-');
        if (false === $path) {
            throw new \RuntimeException('Could not create the video submission test file.');
        }
        $title = 'Visitor submission '.bin2hex(random_bytes(8));
        if (false === file_put_contents($path, $videoBytes)) {
            unlink($path);
            throw new \RuntimeException('Could not write the video submission test file.');
        }

        try {
            $client->request('GET', '/video-submissions');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('form.video-submission-form');

            $client->request('POST', '/video-submissions', [
                '_token' => 'invalid-token',
                'title' => $title,
                'description' => 'A visitor-submitted video waiting for editorial review.',
            ]);
            self::assertResponseStatusCodeSame(403);

            $client->request('GET', '/video-submissions');
            self::assertResponseIsSuccessful();
            $client->submitForm('Zur Prüfung einreichen', [
                'title' => $title,
                'description' => 'A visitor-submitted video waiting for editorial review.',
                'category' => '',
                'video_file' => new UploadedFile($path, 'visitor-video.mp4', 'application/octet-stream', null, true),
                'website' => '',
            ]);
            self::assertResponseRedirects('/video-submissions');
            $client->followRedirect();
            self::assertSelectorTextContains('main', 'zur Prüfung vorgemerkt');

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $video = $entityManager->getRepository(Video::class)->findOneBy(['title' => $title]);
            self::assertInstanceOf(Video::class, $video);
            self::assertSame(PublishingStatus::Draft, $video->getStatus());
            self::assertSame(VideoSourceType::File, $video->getSourceType());
            self::assertFalse($video->isCommentsEnabled());

            $submission = $entityManager->getRepository(VideoSubmission::class)->findOneBy(['video' => $video]);
            self::assertInstanceOf(VideoSubmission::class, $submission);
            self::assertSame(VideoSubmissionStatus::Pending, $submission->getStatus());
            self::assertNull($submission->getSubmitter());

            $client->request('GET', '/videos/'.$video->getId());
            self::assertResponseStatusCodeSame(404);
        } finally {
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            if ($entityManager instanceof EntityManagerInterface) {
                $storedVideo = $entityManager->getRepository(Video::class)->findOneBy(['title' => $title]);
                if ($storedVideo instanceof Video) {
                    $storedSubmission = $entityManager->getRepository(VideoSubmission::class)->findOneBy(['video' => $storedVideo]);
                    if ($storedSubmission instanceof VideoSubmission) {
                        $entityManager->remove($storedSubmission);
                    }
                    $entityManager->remove($storedVideo);
                    $entityManager->flush();
                }
            }
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
