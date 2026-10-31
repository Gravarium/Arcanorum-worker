<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Service\VideoSocialPreviewGenerator;
use App\Service\VideoThumbnailUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VideoSocialPreviewFlowTest extends WebTestCase
{
    public function testPublishedVideoReceivesVersionedPublicPreviewAndDraftsStayPrivate(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $uploads = $client->getContainer()->get(VideoThumbnailUploadService::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(VideoThumbnailUploadService::class, $uploads);

        $suffix = bin2hex(random_bytes(8));
        $source = $this->createPng(320, 240);
        $thumbnailIds = [];
        $videoIds = [];
        $videos = [];

        try {
            $publishedThumbnail = $uploads->storeContents($source);
            $thumbnailIds[] = $publishedThumbnail['object_id'];
            $draftThumbnail = $uploads->storeContents($source);
            $thumbnailIds[] = $draftThumbnail['object_id'];
            $published = new Video(
                'Social preview '.$suffix,
                'Published preview fixture.',
                null,
                VideoSourceType::Url,
                'https://media.example.test/'.$suffix.'.mp4',
                PublishingStatus::Published,
                false,
                true,
            );
            $published->attachThumbnail($publishedThumbnail['object_id'], $publishedThumbnail['mime_type'], $publishedThumbnail['size']);
            $draft = new Video(
                'Private social preview '.$suffix,
                'Draft preview fixture.',
                null,
                VideoSourceType::Url,
                'https://media.example.test/'.$suffix.'-draft.mp4',
                PublishingStatus::Draft,
                false,
                true,
            );
            $draft->attachThumbnail($draftThumbnail['object_id'], $draftThumbnail['mime_type'], $draftThumbnail['size']);
            $missingObjectId = bin2hex(random_bytes(32));
            $missing = new Video(
                'Unavailable social preview '.$suffix,
                'The source object is missing and must not fall back to a public file path.',
                null,
                VideoSourceType::Url,
                'https://media.example.test/'.$suffix.'-missing.mp4',
                PublishingStatus::Published,
                false,
                true,
            );
            $missing->attachThumbnail($missingObjectId, 'image/png', strlen($source));
            $videos = [$published, $draft, $missing];
            $videoIds = array_map(static fn (Video $video): string => $video->getId(), $videos);

            $entityManager->getConnection()->beginTransaction();
            foreach ($videos as $video) {
                $entityManager->persist($video);
            }
            $entityManager->flush();
            $entityManager->getConnection()->commit();

            $version = VideoSocialPreviewGenerator::versionForSourceObjectId($publishedThumbnail['object_id']);
            $client->request('GET', '/videos/'.$published->getId().'/social-preview/'.$version);
            self::assertResponseIsSuccessful();
            self::assertSame('image/jpeg', $client->getResponse()->headers->get('Content-Type'));
            self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
            self::assertTrue($client->getResponse()->headers->getCacheControlDirective('public'));
            self::assertSame('86400', $client->getResponse()->headers->getCacheControlDirective('max-age'));
            self::assertTrue($client->getResponse()->headers->getCacheControlDirective('immutable'));
            $preview = (string) $client->getResponse()->getContent();
            self::assertSame('image/jpeg', new \finfo(FILEINFO_MIME_TYPE)->buffer($preview));
            $imageInfo = getimagesizefromstring($preview);
            self::assertIsArray($imageInfo);
            self::assertSame(1200, $imageInfo[0]);
            self::assertSame(630, $imageInfo[1]);

            $client->request('GET', '/videos/'.$published->getId().'/social-preview/'.str_repeat('0', 64));
            self::assertResponseStatusCodeSame(404);

            $client->request('GET', '/videos/'.$draft->getId().'/social-preview/'.VideoSocialPreviewGenerator::versionForSourceObjectId($draftThumbnail['object_id']));
            self::assertResponseStatusCodeSame(404);

            $client->request('GET', '/videos/'.$missing->getId().'/social-preview/'.VideoSocialPreviewGenerator::versionForSourceObjectId($missingObjectId));
            self::assertResponseStatusCodeSame(503);
            self::assertTrue($client->getResponse()->headers->getCacheControlDirective('no-store'));
        } finally {
            if ($entityManager->getConnection()->isTransactionActive()) {
                $entityManager->getConnection()->rollBack();
            }
            $entityManager->getConnection()->beginTransaction();
            foreach ($videoIds as $videoId) {
                $entityManager->getConnection()->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $videoId]);
            }
            $entityManager->getConnection()->commit();
            foreach ($thumbnailIds as $thumbnailId) {
                $uploads->delete($thumbnailId);
            }
        }
    }

    private function createPng(int $width, int $height): string
    {
        if ($width < 1 || $height < 1) {
            throw new \InvalidArgumentException('Testbilder müssen positive Abmessungen haben.');
        }

        $image = imagecreatetruecolor($width, $height);
        self::assertInstanceOf(\GdImage::class, $image);
        imagefill($image, 0, 0, 0x1A70B1);
        ob_start();
        try {
            self::assertTrue(imagepng($image));
            $contents = ob_get_contents();
        } finally {
            ob_end_clean();
            unset($image);
        }

        self::assertIsString($contents);

        return $contents;
    }
}
