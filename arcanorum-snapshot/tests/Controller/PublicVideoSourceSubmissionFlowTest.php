<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Entity\VideoSubmission;
use App\Entity\VideoSubmissionStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicVideoSourceSubmissionFlowTest extends WebTestCase
{
    public function testSupportedExternalSourceStaysDraftUntilModerated(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $title = 'External source submission '.bin2hex(random_bytes(8));
        try {
            $client->request('GET', '/video-submissions');
            self::assertResponseIsSuccessful();
            $token = (string) $client->getCrawler()->filter('input[name="_token"]')->attr('value');
            $client->request('POST', '/video-submissions', [
                '_token' => $token,
                'title' => $title,
                'description' => 'A supported external video source waiting for review.',
                'category' => '',
                'source_url' => 'https://youtu.be/dQw4w9WgXcQ',
                'website' => '',
            ]);
            self::assertResponseRedirects('/video-submissions');

            $video = $entityManager->getRepository(Video::class)->findOneBy(['title' => $title]);
            self::assertInstanceOf(Video::class, $video);
            self::assertSame(VideoSourceType::Embed, $video->getSourceType());
            self::assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $video->getSourceUrl());
            self::assertSame(PublishingStatus::Draft, $video->getStatus());
            self::assertFalse($video->isCommentsEnabled());

            $submission = $entityManager->getRepository(VideoSubmission::class)->findOneBy(['video' => $video]);
            self::assertInstanceOf(VideoSubmission::class, $submission);
            self::assertSame(VideoSubmissionStatus::Pending, $submission->getStatus());
            self::assertNull($submission->getSubmitter());

            $client->request('GET', '/videos/'.$video->getId());
            self::assertResponseStatusCodeSame(404);
        } finally {
            $video = $entityManager->getRepository(Video::class)->findOneBy(['title' => $title]);
            if ($video instanceof Video) {
                $submission = $entityManager->getRepository(VideoSubmission::class)->findOneBy(['video' => $video]);
                if ($submission instanceof VideoSubmission) {
                    $entityManager->remove($submission);
                }
                $entityManager->remove($video);
                $entityManager->flush();
            }
        }
    }

    public function testUntrustedExternalSourceIsRejectedWithoutCreatingARecord(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $title = 'Rejected external source '.bin2hex(random_bytes(8));

        $client->request('GET', '/video-submissions');
        self::assertResponseIsSuccessful();
        $token = (string) $client->getCrawler()->filter('input[name="_token"]')->attr('value');
        $client->request('POST', '/video-submissions', [
            '_token' => $token,
            'title' => $title,
            'description' => '',
            'category' => '',
            'source_url' => 'https://attacker.example/video',
            'website' => '',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'sichere, einbettbare Videos von unterstützten Providern');
        self::assertNull($entityManager->getRepository(Video::class)->findOneBy(['title' => $title]));
    }
}
