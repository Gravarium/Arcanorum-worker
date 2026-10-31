<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentSeries;
use App\Entity\PublishingStatus;
use App\Entity\SeriesGenre;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class SeriesGenreFeaturedFlowTest extends WebTestCase
{
    public function testFeaturedGenresAndSeriesAreBoundedPublishedAndOnlyOnTheUnfilteredFirstPage(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $suffix = bin2hex(random_bytes(6));
        $genres = [];

        for ($index = 0; $index < 11; ++$index) {
            $genre = new SeriesGenre('M52 Featured '.$suffix.' '.$index);
            $genre->setFeatured(true, $index);
            $genres[] = $genre;
            $entityManager->persist($genre);
            $seriesCount = 0 === $index ? 11 : 1;
            for ($item = 0; $item < $seriesCount; ++$item) {
                $entry = new ContentSeries(
                    sprintf('M52 series %s %d %d', $suffix, $index, $item),
                    sprintf('m52-%s-featured-%d-%d', $suffix, $index, $item),
                    'Published series description',
                    PublishingStatus::Published,
                    [$genre],
                );
                $entityManager->persist($entry);
            }
        }

        $emptyFeaturedGenre = new SeriesGenre('M52 Featured '.$suffix.' empty');
        $emptyFeaturedGenre->setFeatured(true, 0);
        $draftFeaturedGenre = new SeriesGenre('M52 Featured '.$suffix.' draft-only');
        $draftFeaturedGenre->setFeatured(true, 0);
        $entityManager->persist($emptyFeaturedGenre);
        $entityManager->persist($draftFeaturedGenre);
        $entityManager->persist(new ContentSeries(
            'M52 draft '.$suffix,
            'm52-'.$suffix.'-draft',
            'Unpublished series description',
            PublishingStatus::Draft,
            [$draftFeaturedGenre],
        ));
        for ($index = 0; $index < 4; ++$index) {
            $entry = new ContentSeries(
                sprintf('M52 unfeatured %s %d', $suffix, $index),
                sprintf('m52-%s-unfeatured-%d', $suffix, $index),
                'Published series description',
                PublishingStatus::Published,
            );
            $entityManager->persist($entry);
        }
        $entityManager->flush();
        $featuredGenreNames = array_map(static fn (SeriesGenre $genre): string => $genre->getName(), $genres);
        $entityManager->clear();

        try {
            $client->request('GET', '/series');
            self::assertResponseIsSuccessful();
            $blocks = $client->getCrawler()->filter('section.featured-series-group');
            self::assertCount(10, $blocks);
            $blockMarkup = implode("\n", $blocks->each(static fn (Crawler $block): string => $block->outerHtml()));
            self::assertStringContainsString($featuredGenreNames[0], $blocks->eq(0)->filter('h2')->text());
            self::assertStringContainsString($featuredGenreNames[1], $blocks->eq(1)->filter('h2')->text());
            self::assertStringContainsString($featuredGenreNames[9], $blockMarkup);
            self::assertStringNotContainsString($featuredGenreNames[10], $blockMarkup);
            self::assertStringNotContainsString($emptyFeaturedGenre->getName(), $blockMarkup);
            self::assertStringNotContainsString($draftFeaturedGenre->getName(), $blockMarkup);
            self::assertStringNotContainsString('M52 draft '.$suffix, $blockMarkup);

            $firstBlock = $client->getCrawler()->filter('section.featured-series-group[aria-labelledby="featured-series-genre-1"]');
            self::assertCount(1, $firstBlock);
            self::assertCount(10, $firstBlock->filter('article.content-card'));

            $client->request('GET', '/series?page=2');
            self::assertResponseIsSuccessful();
            self::assertCount(0, $client->getCrawler()->filter('section.featured-series-group'));

            $client->request('GET', '/series?genre='.rawurlencode($featuredGenreNames[0]));
            self::assertResponseIsSuccessful();
            self::assertCount(0, $client->getCrawler()->filter('section.featured-series-group'));
        } finally {
            $connection->executeStatement('DELETE FROM content_series WHERE slug LIKE :prefix', ['prefix' => 'm52-'.$suffix.'-%']);
            $connection->executeStatement('DELETE FROM series_genres WHERE normalized_name LIKE :prefix', ['prefix' => 'm52 featured '.$suffix.'%']);
        }
    }
}
