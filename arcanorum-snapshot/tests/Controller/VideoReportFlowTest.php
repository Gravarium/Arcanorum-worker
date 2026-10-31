<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoReport;
use App\Entity\VideoReportReason;
use App\Entity\VideoReportStatus;
use App\Entity\VideoSourceType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VideoReportFlowTest extends WebTestCase
{
    public function testPublishedVideoCanBeReportedButDraftCannot(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(8));
        $published = new Video(
            'Reportable video '.$suffix,
            'A published video used by the report flow regression.',
            null,
            VideoSourceType::Url,
            'https://video.example.test/report-'.$suffix,
            PublishingStatus::Published,
            false,
            true,
        );
        $draft = new Video(
            'Private draft '.$suffix,
            'A draft must not accept public reports.',
            null,
            VideoSourceType::Url,
            'https://video.example.test/draft-'.$suffix,
            PublishingStatus::Draft,
            false,
            true,
        );

        try {
            $entityManager->persist($published);
            $entityManager->persist($draft);
            $entityManager->flush();

            $client->request('GET', '/videos/'.$published->getId().'?private=do-not-share');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('form.video-report-form');
            self::assertSelectorTextContains('#video-share-heading', 'Video teilen');
            self::assertSelectorExists('section#video-share a[rel="bookmark"][href="/videos/'.$published->getId().'"]');
            $shareUrl = (string) $client->getCrawler()->filter('section#video-share a[rel="bookmark"]')->attr('href');
            self::assertStringNotContainsString('do-not-share', $shareUrl);
            self::assertStringNotContainsString('untrusted.example.test', $shareUrl);

            $token = $client->getCrawler()
                ->filter('form.video-report-form input[name="_token"]')
                ->attr('value');
            self::assertIsString($token);

            $client->request('POST', '/videos/'.$published->getId().'/report', [
                '_token' => 'invalid-token',
                'reason' => VideoReportReason::BrokenMedia->value,
                'details' => 'The video source does not load.',
                'website' => '',
            ]);
            self::assertResponseStatusCodeSame(403);

            $client->request('POST', '/videos/'.$published->getId().'/report', [
                '_token' => $token,
                'reason' => VideoReportReason::BrokenMedia->value,
                'details' => 'The video source does not load.',
                'website' => '',
            ]);
            self::assertResponseRedirects('/videos/'.$published->getId().'#video-report');

            $currentManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $currentManager);
            $publishedRecord = $currentManager->getRepository(Video::class)->find($published->getId());
            self::assertInstanceOf(Video::class, $publishedRecord);
            $report = $currentManager->getRepository(VideoReport::class)->findOneBy(['video' => $publishedRecord]);
            self::assertInstanceOf(VideoReport::class, $report);
            self::assertSame(VideoReportReason::BrokenMedia, $report->getReason());
            self::assertSame(VideoReportStatus::Pending, $report->getStatus());
            self::assertSame('The video source does not load.', $report->getDetails());

            $client->request('GET', '/videos/'.$published->getId(), [], [], ['HTTP_HOST' => 'untrusted.example.test']);
            self::assertResponseIsSuccessful();
            $shareUrl = (string) $client->getCrawler()->filter('section#video-share a[rel="bookmark"]')->attr('href');
            self::assertSame('/videos/'.$published->getId(), $shareUrl);

            $client->request('POST', '/videos/'.$draft->getId().'/report', [
                'reason' => VideoReportReason::Other->value,
                'details' => '',
            ]);
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/videos/'.$draft->getId());
            self::assertResponseStatusCodeSame(404);
            self::assertSelectorNotExists('#video-share');
        } finally {
            $cleanupManager = $client->getContainer()->get(EntityManagerInterface::class);
            if ($cleanupManager instanceof EntityManagerInterface) {
                foreach ([$published->getId(), $draft->getId()] as $videoId) {
                    $storedVideo = $cleanupManager->getRepository(Video::class)->find($videoId);
                    if (!$storedVideo instanceof Video) {
                        continue;
                    }

                    foreach ($cleanupManager->getRepository(VideoReport::class)->findBy(['video' => $storedVideo]) as $report) {
                        $cleanupManager->remove($report);
                    }

                    $cleanupManager->remove($storedVideo);
                }

                $cleanupManager->flush();
            }
        }
    }
}
