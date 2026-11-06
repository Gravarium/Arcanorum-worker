<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\CommentContentType;
use App\Entity\ContentComment;
use App\Entity\NewsArticle;
use App\Entity\PublishingStatus;
use App\Module\News\NewsArchiveSort;
use App\Repository\NewsArticleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class NewsArticleRepositoryTest extends KernelTestCase
{
    public function testPublishedArticlesUseSelectedSortAndApprovedCommentCounts(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $articles = static::getContainer()->get(NewsArticleRepository::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(NewsArticleRepository::class, $articles);

        $suffix = bin2hex(random_bytes(6));
        $newest = $this->article('Newest '.$suffix, 'newest-'.$suffix, true, '2026-11-05 12:00:00 UTC');
        $popular = $this->article('Popular '.$suffix, 'popular-'.$suffix, true, '2026-11-04 12:00:00 UTC');
        $commented = $this->article('Commented '.$suffix, 'commented-'.$suffix, true, '2026-11-03 12:00:00 UTC');
        $commentsDisabled = $this->article('Comments disabled '.$suffix, 'comments-disabled-'.$suffix, false, '2026-11-02 12:00:00 UTC');
        $pending = new ContentComment(CommentContentType::News, $popular->getId(), $popular->getTitle(), 'Reviewer', 'Pending comment text.');
        $approvedOne = new ContentComment(CommentContentType::News, $commented->getId(), $commented->getTitle(), 'Reviewer', 'Approved comment one.');
        $approvedTwo = new ContentComment(CommentContentType::News, $commented->getId(), $commented->getTitle(), 'Reviewer', 'Approved comment two.');
        $approvedHidden = new ContentComment(CommentContentType::News, $commentsDisabled->getId(), $commentsDisabled->getTitle(), 'Reviewer', 'No longer countable.');
        $approvedOne->approve();
        $approvedTwo->approve();
        $approvedHidden->approve();
        $connection = $entityManager->getConnection();

        try {
            foreach ([$newest, $popular, $commented, $commentsDisabled, $pending, $approvedOne, $approvedTwo, $approvedHidden] as $entity) {
                $entityManager->persist($entity);
            }
            $entityManager->flush();
            $connection->executeStatement('UPDATE news_articles SET view_count = 2 WHERE id = :id', ['id' => $newest->getId()]);
            $connection->executeStatement('UPDATE news_articles SET view_count = 9 WHERE id = :id', ['id' => $popular->getId()]);
            $connection->executeStatement('UPDATE news_articles SET view_count = 4 WHERE id = :id', ['id' => $commented->getId()]);

            self::assertSame(
                [$popular->getId(), $commented->getId(), $newest->getId(), $commentsDisabled->getId()],
                array_map(static fn (NewsArticle $article): string => $article->getId(), $articles->findPublished(search: $suffix, sort: NewsArchiveSort::Popular)),
            );
            self::assertSame(
                [$commented->getId(), $newest->getId(), $popular->getId(), $commentsDisabled->getId()],
                array_map(static fn (NewsArticle $article): string => $article->getId(), $articles->findPublished(search: $suffix, sort: NewsArchiveSort::Comments)),
            );
            self::assertSame(
                [$newest->getId(), $popular->getId(), $commented->getId(), $commentsDisabled->getId()],
                array_map(static fn (NewsArticle $article): string => $article->getId(), $articles->findPublished(search: $suffix)),
            );
        } finally {
            $connection->executeStatement('DELETE FROM content_comments WHERE content_type = :type AND content_id IN (:ids)', [
                'type' => 'news',
                'ids' => [$newest->getId(), $popular->getId(), $commented->getId(), $commentsDisabled->getId()],
            ], ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING]);
            $connection->executeStatement('DELETE FROM news_articles WHERE id IN (:ids)', [
                'ids' => [$newest->getId(), $popular->getId(), $commented->getId(), $commentsDisabled->getId()],
            ], ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING]);
        }
    }

    public function testPublicViewCountRejectsHiddenUnpublishedFutureAndSaturatedArticles(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $articles = static::getContainer()->get(NewsArticleRepository::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(NewsArticleRepository::class, $articles);
        $suffix = bin2hex(random_bytes(6));
        $published = $this->article('Published '.$suffix, 'published-'.$suffix, true);
        $draft = new NewsArticle('Draft '.$suffix, 'draft-'.$suffix, null, null, 'Hidden draft body.', PublishingStatus::Draft, false, true);
        $membersOnly = new NewsArticle('Members '.$suffix, 'members-'.$suffix, null, null, 'Restricted body.', PublishingStatus::Published, false, true, membersOnly: true);
        $future = $this->article('Future '.$suffix, 'future-'.$suffix, true);
        $connection = $entityManager->getConnection();

        try {
            foreach ([$published, $draft, $membersOnly, $future] as $article) {
                $entityManager->persist($article);
            }
            $entityManager->flush();
            $connection->executeStatement(
                'UPDATE news_articles SET published_at = CURRENT_TIMESTAMP + INTERVAL \'1 day\' WHERE id = :id',
                ['id' => $future->getId()],
            );

            self::assertTrue($articles->recordPublicView($published->getId()));
            self::assertFalse($articles->recordPublicView($draft->getId()));
            self::assertFalse($articles->recordPublicView($membersOnly->getId()));
            self::assertFalse($articles->recordPublicView($future->getId()));
            self::assertSame('1', $connection->fetchOne('SELECT view_count::text FROM news_articles WHERE id = :id', ['id' => $published->getId()]));

            $connection->executeStatement('UPDATE news_articles SET view_count = 2147483647 WHERE id = :id', ['id' => $published->getId()]);
            self::assertFalse($articles->recordPublicView($published->getId()));
            self::assertSame('2147483647', $connection->fetchOne('SELECT view_count::text FROM news_articles WHERE id = :id', ['id' => $published->getId()]));
        } finally {
            $connection->executeStatement('DELETE FROM content_comments WHERE content_type = :type AND content_id IN (:ids)', [
                'type' => 'news',
                'ids' => [$published->getId(), $draft->getId(), $membersOnly->getId(), $future->getId()],
            ], ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING]);
            $connection->executeStatement('DELETE FROM news_articles WHERE id IN (:ids)', [
                'ids' => [$published->getId(), $draft->getId(), $membersOnly->getId(), $future->getId()],
            ], ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING]);
        }
    }

    private function article(string $title, string $slug, bool $commentsEnabled, ?string $publishedAt = null): NewsArticle
    {
        $article = new NewsArticle($title, $slug, null, null, $title.' body', PublishingStatus::Published, false, $commentsEnabled);
        if (null !== $publishedAt) {
            $article->setPublicationAt(new \DateTimeImmutable($publishedAt));
        }

        return $article;
    }
}
