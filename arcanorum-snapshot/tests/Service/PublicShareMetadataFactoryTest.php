<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Module\Feed\Service\CanonicalPublicUrlGenerator;
use App\Service\PublicShareMetadataFactory;
use App\Service\VideoSocialPreviewGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class PublicShareMetadataFactoryTest extends TestCase
{
    public function testVideoMetadataUsesVersionedGeneratedSocialPreviewDimensions(): void
    {
        $objectId = str_repeat('a', 64);
        $video = new Video(
            'Example title',
            'Example description.',
            null,
            VideoSourceType::Url,
            'https://media.example.test/example.mp4',
            PublishingStatus::Published,
            false,
            true,
        );
        $video->attachThumbnail($objectId, 'image/png', 64);
        $factory = new PublicShareMetadataFactory($this->publicUrls());

        $metadata = $factory->forVideo($video);

        self::assertIsArray($metadata);
        self::assertSame('https://arcanorum.example.test/videos/'.$video->getId().'/social-preview/'.VideoSocialPreviewGenerator::versionForSourceObjectId($objectId), $metadata['image_url']);
        self::assertStringNotContainsString($objectId, $metadata['image_url']);
        self::assertSame('image/jpeg', $metadata['image_mime_type']);
        self::assertSame(1200, $metadata['image_width']);
        self::assertSame(630, $metadata['image_height']);
    }

    public function testVideoWithoutValidThumbnailHasNoSocialImage(): void
    {
        $video = new Video(
            'Example title',
            'Example description.',
            null,
            VideoSourceType::Url,
            'https://media.example.test/example.mp4',
            PublishingStatus::Published,
            false,
            true,
        );
        $metadata = new PublicShareMetadataFactory($this->publicUrls())->forVideo($video);

        self::assertIsArray($metadata);
        self::assertNull($metadata['image_url']);
        self::assertNull($metadata['image_mime_type']);
        self::assertNull($metadata['image_width']);
        self::assertNull($metadata['image_height']);
    }

    private function publicUrls(): CanonicalPublicUrlGenerator
    {
        $routes = new RouteCollection();
        $routes->add('video_show', new Route('/videos/{id}', requirements: ['id' => '[a-f0-9]{32}']));
        $routes->add('video_social_preview', new Route('/videos/{id}/social-preview/{version}', requirements: [
            'id' => '[a-f0-9]{32}',
            'version' => '[a-f0-9]{64}',
        ]));
        $router = new UrlGenerator($routes, new RequestContext());

        return new CanonicalPublicUrlGenerator($router, 'https://arcanorum.example.test');
    }
}
