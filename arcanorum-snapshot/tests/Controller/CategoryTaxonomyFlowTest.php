<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentCategory;
use App\Entity\ContentCategoryType;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CategoryTaxonomyFlowTest extends WebTestCase
{
    public function testVideoCategoryDirectoryShowsRootCategoriesAndParentPageShowsChildren(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(6));
        $rootSlug = 'directory-root-'.$suffix;
        $childSlug = 'directory-child-'.$suffix;
        $newsSlug = 'directory-news-'.$suffix;
        $newsChildSlug = 'directory-news-child-'.$suffix;
        $root = new ContentCategory('Directory root '.$suffix, ContentCategoryType::Video);
        $root->updateTaxonomy($rootSlug, 'Root category description.', null, null, null, 10);
        $child = new ContentCategory('Directory child '.$suffix, ContentCategoryType::Video);
        $child->updateTaxonomy($childSlug, '', null, null, null, 0);
        $child->setParent($root);
        $news = new ContentCategory('Directory news '.$suffix, ContentCategoryType::News);
        $news->updateTaxonomy($newsSlug, '', null, null, null, 0);
        $newsChild = new ContentCategory('Directory news child '.$suffix, ContentCategoryType::News);
        $newsChild->updateTaxonomy($newsChildSlug, '<p>Safe child description.</p><script>alert("child")</script>', null, null, null, 0);
        $newsChild->setParent($news);

        try {
            $entityManager->persist($root);
            $entityManager->persist($child);
            $entityManager->persist($news);
            $entityManager->persist($newsChild);
            $entityManager->flush();

            $client->request('GET', '/videos/categories');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('a[href="/videos/category/'.$rootSlug.'"]');
            self::assertSelectorNotExists('a[href="/videos/category/'.$childSlug.'"]');
            self::assertSelectorNotExists('a[href="/videos/category/'.$newsSlug.'"]');
            self::assertSelectorExists('span.category-artwork-fallback');

            $client->request('GET', '/videos/category/'.$rootSlug);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h2#video-subcategories-heading', 'Unterkategorien');
            self::assertSelectorExists('a[href="/videos/category/'.$childSlug.'"]');
            self::assertSelectorExists('span.category-artwork-fallback');
            self::assertSelectorTextContains('main', $root->getName());

            $client->request('GET', '/videos/category/'.$childSlug);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('a.category-parent-link[href="/videos/category/'.$rootSlug.'"]');

            $client->request('GET', '/news');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('nav.category-directory a[href="/news/category/'.$newsSlug.'"]');
            self::assertSelectorExists('nav.category-directory li.category-level-1 a[href="/news/category/'.$newsChildSlug.'"]');

            $client->request('GET', '/news/category/'.$newsSlug);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('section[aria-labelledby="news-subcategories-heading"] a[href="/news/category/'.$newsChildSlug.'"]');
            self::assertSelectorTextContains('section[aria-labelledby="news-subcategories-heading"]', 'Safe child description.');
            self::assertSelectorNotExists('section[aria-labelledby="news-subcategories-heading"] script');
            self::assertStringNotContainsString('alert("child")', (string) $client->getResponse()->getContent());

            $client->request('GET', '/news/category/'.$newsChildSlug);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('a.category-parent-link[href="/news/category/'.$newsSlug.'"]');
        } finally {
            $cleanupManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $cleanupManager);
            $connection = $cleanupManager->getConnection();
            $connection->executeStatement('DELETE FROM content_categories WHERE id = :id', ['id' => $child->getId()]);
            $connection->executeStatement('DELETE FROM content_categories WHERE id = :id', ['id' => $root->getId()]);
            $connection->executeStatement('DELETE FROM content_categories WHERE id = :id', ['id' => $newsChild->getId()]);
            $connection->executeStatement('DELETE FROM content_categories WHERE id = :id', ['id' => $news->getId()]);
        }
    }

    public function testHomeDisplaysOnlyFeaturedCategoriesWithPublishedVideosAndCapsSectionsAndCards(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $suffix = bin2hex(random_bytes(6));
        $categories = [];
        $videos = [];

        for ($index = 0; $index < 11; ++$index) {
            $category = new ContentCategory('Homepage category '.sprintf('%02d', $index).' '.$suffix, ContentCategoryType::Video);
            $category->updateTaxonomy('homepage-category-'.$index.'-'.$suffix, '', null, null, null, $index);
            $category->setFeatured(true);
            $categories[] = $category;
            $videoCount = 0 === $index ? 12 : 1;
            for ($videoIndex = 0; $videoIndex < $videoCount; ++$videoIndex) {
                $video = new Video(
                    'Homepage item '.$index.'-'.$videoIndex.' '.$suffix,
                    'Video used to verify featured category sections.',
                    $category->getName(),
                    VideoSourceType::Url,
                    'https://video.example.test/home-category-'.$index.'-'.$videoIndex.'.mp4',
                    PublishingStatus::Published,
                    false,
                    true,
                );
                $videos[] = $video;
            }
        }

        $draftOnly = new ContentCategory('Homepage draft only '.$suffix, ContentCategoryType::Video);
        $draftOnly->updateTaxonomy('homepage-draft-only-'.$suffix, '', null, null, null, 12);
        $draftOnly->setFeatured(true);
        $empty = new ContentCategory('Homepage empty '.$suffix, ContentCategoryType::Video);
        $empty->updateTaxonomy('homepage-empty-'.$suffix, '', null, null, null, 13);
        $empty->setFeatured(true);
        $scheduledOnly = new ContentCategory('Homepage scheduled only '.$suffix, ContentCategoryType::Video);
        $scheduledOnly->updateTaxonomy('homepage-scheduled-only-'.$suffix, '', null, null, null, 14);
        $scheduledOnly->setFeatured(true);
        $categories[] = $draftOnly;
        $categories[] = $empty;
        $categories[] = $scheduledOnly;
        $draft = new Video(
            'Homepage unpublished item '.$suffix,
            'Draft videos must not make a category visible on the homepage.',
            $draftOnly->getName(),
            VideoSourceType::Url,
            'https://video.example.test/home-category-draft-'.$suffix.'.mp4',
            PublishingStatus::Draft,
            false,
            true,
        );
        $videos[] = $draft;
        $scheduled = new Video(
            'Homepage scheduled item '.$suffix,
            'A scheduled video must not be shown before publication.',
            $scheduledOnly->getName(),
            VideoSourceType::Url,
            'https://video.example.test/home-category-scheduled-'.$suffix.'.mp4',
            PublishingStatus::Published,
            false,
            true,
        );
        $videos[] = $scheduled;

        try {
            foreach ($categories as $category) {
                $entityManager->persist($category);
            }
            foreach ($videos as $video) {
                $entityManager->persist($video);
            }
            $entityManager->flush();

            foreach ($videos as $index => $video) {
                if (PublishingStatus::Published !== $video->getStatus()) {
                    continue;
                }
                $ageMinutes = 30 - $index;
                $entityManager->getConnection()->executeStatement(
                    "UPDATE videos SET published_at = CURRENT_TIMESTAMP - (CAST(:age AS INTEGER) * INTERVAL '1 minute') WHERE id = :id",
                    ['age' => $ageMinutes, 'id' => $video->getId()],
                );
            }
            $entityManager->getConnection()->executeStatement(
                "UPDATE videos SET published_at = CURRENT_TIMESTAMP + INTERVAL '1 day' WHERE id = :id",
                ['id' => $scheduled->getId()],
            );

            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            $featuredSections = $client->getCrawler()->filter('section[aria-labelledby^="home-category-"]');
            self::assertSame(10, $featuredSections->count());
            self::assertSame('home-category-'.$categories[0]->getId(), $featuredSections->first()->attr('aria-labelledby'));
            self::assertSame('home-category-'.$categories[9]->getId(), $featuredSections->last()->attr('aria-labelledby'));
            self::assertSame(0, $client->getCrawler()->filter('section[aria-labelledby="home-category-'.$categories[10]->getId().'"]')->count());

            $firstCategorySelector = 'section[aria-labelledby="home-category-'.$categories[0]->getId().'"]';
            $firstCategoryCards = $client->getCrawler()->filter($firstCategorySelector.' .content-grid h3 a');
            self::assertSame(10, $firstCategoryCards->count());
            self::assertSame('Homepage item 0-11 '.$suffix, trim($firstCategoryCards->first()->text()));
            self::assertSame(0, $client->getCrawler()->filter('section[aria-labelledby="home-category-'.$draftOnly->getId().'"]')->count());
            self::assertSame(0, $client->getCrawler()->filter('section[aria-labelledby="home-category-'.$empty->getId().'"]')->count());
            self::assertSame(0, $client->getCrawler()->filter('section[aria-labelledby="home-category-'.$scheduledOnly->getId().'"]')->count());
            $visibleTitles = [];
            foreach ($firstCategoryCards as $card) {
                $visibleTitles[] = trim($card->textContent);
            }
            self::assertNotContains('Homepage item 0-0 '.$suffix, $visibleTitles);
            self::assertNotContains('Homepage item 0-1 '.$suffix, $visibleTitles);
        } finally {
            $cleanupManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $cleanupManager);
            $connection = $cleanupManager->getConnection();
            foreach ($videos as $video) {
                $connection->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $video->getId()]);
            }
            foreach (array_reverse($categories) as $category) {
                $connection->executeStatement('DELETE FROM content_categories WHERE id = :id', ['id' => $category->getId()]);
            }
        }
    }
}
