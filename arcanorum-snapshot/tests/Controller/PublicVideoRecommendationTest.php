<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentRating;
use App\Entity\ContentRatingValue;
use App\Entity\ContentTag;
use App\Entity\MemberAccount;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Service\SiteSettingsManager;
use App\Service\SiteSettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicVideoRecommendationTest extends WebTestCase
{
    public function testDetailRecommendationsUseRelatedPublicContentAndAggregateRatings(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $settingsManager = $client->getContainer()->get(SiteSettingsManager::class);
        $settingsProvider = $client->getContainer()->get(SiteSettingsProvider::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(SiteSettingsManager::class, $settingsManager);
        self::assertInstanceOf(SiteSettingsProvider::class, $settingsProvider);
        $settings = $settingsProvider->current();
        $original = [
            'related_video_limit' => (string) $settings->getRelatedVideoLimit(),
            'top_rated_video_limit' => (string) $settings->getTopRatedVideoLimit(),
            'video_share_widget_enabled' => $settings->isVideoShareWidgetEnabled() ? '1' : '0',
        ];

        $suffix = bin2hex(random_bytes(6));
        $tag = new ContentTag('topic-'.$suffix);
        $source = $this->video('Source '.$suffix, 'Action', PublishingStatus::Published, $tag);
        $related = $this->video('Related '.$suffix, null, PublishingStatus::Published, $tag);
        $categoryMatch = $this->video('Category match '.$suffix, 'Action', PublishingStatus::Published, null);
        $rated = $this->video('Rated '.$suffix, 'Action', PublishingStatus::Published, null);
        $lowerRated = $this->video('Lower-rated '.$suffix, 'Action', PublishingStatus::Published, null);
        $unrelated = $this->video('Unrelated '.$suffix, 'Elsewhere', PublishingStatus::Published, null);
        $unclassified = $this->video('Unclassified source '.$suffix, null, PublishingStatus::Published, null);
        $draft = $this->video('Hidden draft '.$suffix, null, PublishingStatus::Draft, $tag);
        $future = $this->video('Hidden future '.$suffix, null, PublishingStatus::Published, $tag);
        $memberOne = new MemberAccount('rank-one-'.$suffix.'@example.test', 'rankone'.$suffix, 'Private voter one');
        $memberTwo = new MemberAccount('rank-two-'.$suffix.'@example.test', 'ranktwo'.$suffix, 'Private voter two');
        $memberThree = new MemberAccount('rank-three-'.$suffix.'@example.test', 'rankthree'.$suffix, 'Private voter three');
        $ratingOne = new ContentRating($memberOne, $rated, null, ContentRatingValue::Like);
        $ratingTwo = new ContentRating($memberTwo, $rated, null, ContentRatingValue::Like);
        $ratingLower = new ContentRating($memberThree, $lowerRated, null, ContentRatingValue::Like);

        try {
            foreach ([$tag, $source, $related, $categoryMatch, $rated, $lowerRated, $unrelated, $unclassified, $draft, $future, $memberOne, $memberTwo, $memberThree, $ratingOne, $ratingTwo, $ratingLower] as $entity) {
                $entityManager->persist($entity);
            }
            $entityManager->flush();
            $entityManager->getConnection()->executeStatement(
                'UPDATE videos SET published_at = CURRENT_TIMESTAMP + INTERVAL \'1 day\' WHERE id = :id',
                ['id' => $future->getId()],
            );
            self::assertSame([], $settingsManager->updatePublicDisplayOptions([
                'related_video_limit' => '6',
                'top_rated_video_limit' => '6',
                'video_share_widget_enabled' => '0',
            ]));

            $client->request('GET', '/videos/'.$source->getId());

            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('#video-share');
            self::assertSelectorTextContains('#related-videos-heading', 'Ähnliche Videos');
            self::assertSelectorTextContains('#top-rated-videos-heading', 'Am besten bewertete Videos');
            $html = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('Related '.$suffix, $html);
            self::assertStringContainsString('Category match '.$suffix, $html);
            self::assertStringContainsString('Rated '.$suffix, $html);
            self::assertStringContainsString('Lower-rated '.$suffix, $html);
            self::assertStringNotContainsString('Unrelated '.$suffix, $html);
            self::assertStringNotContainsString('Hidden draft '.$suffix, $html);
            self::assertStringNotContainsString('Hidden future '.$suffix, $html);
            self::assertStringNotContainsString($memberOne->getEmail(), $html);
            self::assertStringNotContainsString($memberTwo->getEmail(), $html);

            self::assertSame([], $settingsManager->updatePublicDisplayOptions(['video_share_widget_enabled' => '1']));
            $client->request('GET', '/videos/'.$source->getId(), server: ['HTTP_HOST' => 'attacker.example.test']);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('#video-share-heading', 'Diesen Inhalt teilen');
            $facebookHref = $client->getCrawler()->filter('#video-share [data-testid="share-facebook"]')->attr('href');
            self::assertIsString($facebookHref);
            $facebookQuery = [];
            parse_str((string) parse_url($facebookHref, PHP_URL_QUERY), $facebookQuery);
            self::assertSame('https://arcanorum.example.test/videos/'.$source->getId(), $facebookQuery['u'] ?? null);
            self::assertSelectorExists('#video-share a[data-testid="share-x"][target="_blank"][rel="nofollow noopener noreferrer"][referrerpolicy="no-referrer"]');
            self::assertSelectorExists('#video-share a[data-testid="share-pinterest"][target="_blank"][rel="nofollow noopener noreferrer"][referrerpolicy="no-referrer"]');
            self::assertSelectorExists('#video-share a[data-testid="public-share-url"]');
            self::assertStringNotContainsString('attacker.example.test', (string) $client->getResponse()->getContent());
            self::assertSame([], $settingsManager->updatePublicDisplayOptions(['video_share_widget_enabled' => '0']));

            $topRatedSection = [];
            $relatedSection = [];
            self::assertSame(1, preg_match('~<section class="public-section" aria-labelledby="related-videos-heading".*?</section>~s', $html, $relatedSection));
            $matchedRelatedSection = $relatedSection[0] ?? '';
            self::assertNotSame('', $matchedRelatedSection);
            self::assertStringContainsString('Related '.$suffix, $matchedRelatedSection);
            self::assertStringContainsString('Category match '.$suffix, $matchedRelatedSection);
            self::assertStringNotContainsString('Unrelated '.$suffix, $matchedRelatedSection);

            $topRatedSection = [];
            self::assertSame(1, preg_match('~<section class="public-section" aria-labelledby="top-rated-videos-heading".*?</section>~s', $html, $topRatedSection));
            $matchedSection = $topRatedSection[0] ?? '';
            self::assertNotSame('', $matchedSection);
            self::assertMatchesRegularExpression('/<h3><a[^>]*>Rated '.$suffix.'<\/a><\/h3>.*?<h3><a[^>]*>Lower-rated '.$suffix.'<\/a><\/h3>/s', $matchedSection);

            self::assertSame([], $settingsManager->updatePublicDisplayOptions(['top_rated_video_limit' => '0']));
            $client->request('GET', '/videos/'.$unclassified->getId());
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('#related-videos-heading');
        } finally {
            self::assertSame([], $settingsManager->updatePublicDisplayOptions($original));
            $connection = $entityManager->getConnection();
            $connection->executeStatement(
                'DELETE FROM videos WHERE id IN (:source, :related, :category_match, :rated, :lower_rated, :unrelated, :unclassified, :draft, :future)',
                [
                    'source' => $source->getId(),
                    'related' => $related->getId(),
                    'category_match' => $categoryMatch->getId(),
                    'rated' => $rated->getId(),
                    'lower_rated' => $lowerRated->getId(),
                    'unrelated' => $unrelated->getId(),
                    'unclassified' => $unclassified->getId(),
                    'draft' => $draft->getId(),
                    'future' => $future->getId(),
                ],
            );
            $connection->executeStatement('DELETE FROM content_tags WHERE id = :id', ['id' => $tag->getId()]);
            $connection->executeStatement('DELETE FROM member_accounts WHERE id IN (:one, :two, :three)', [
                'one' => $memberOne->getId(),
                'two' => $memberTwo->getId(),
                'three' => $memberThree->getId(),
            ]);
        }
    }

    private function video(string $title, ?string $category, PublishingStatus $status, ?ContentTag $tag): Video
    {
        $video = new Video(
            $title,
            'A test video for public recommendation visibility.',
            $category,
            VideoSourceType::Url,
            'https://videos.example.test/'.bin2hex(random_bytes(8)).'.mp4',
            $status,
            false,
            true,
        );
        if (null !== $tag) {
            $video->replaceTags([$tag]);
        }

        return $video;
    }
}
