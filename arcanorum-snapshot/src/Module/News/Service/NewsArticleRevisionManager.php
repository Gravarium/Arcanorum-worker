<?php

declare(strict_types=1);

namespace App\Module\News\Service;

use App\Entity\AdminUser;
use App\Entity\NewsArticle;
use App\Entity\NewsArticleRevision;
use App\Repository\NewsArticleRevisionRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class NewsArticleRevisionManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private NewsArticleRevisionRepository $revisions,
    ) {
    }

    public function recordCurrent(NewsArticle $article, AdminUser $editor): void
    {
        $connection = $this->entityManager->getConnection();
        if (!$connection->isTransactionActive()) {
            throw new \LogicException('Versionsänderungen müssen in derselben Transaktion wie der Beitrag gespeichert werden.');
        }

        $lockedId = $connection->fetchOne(
            'SELECT id FROM news_articles WHERE id = :id FOR UPDATE',
            ['id' => $article->getId()],
        );
        if ($lockedId !== $article->getId()) {
            throw new \LogicException('Der News-Beitrag konnte für die Versionsablage nicht gesperrt werden.');
        }

        $latest = $this->revisions->findLatestForArticle($article);
        if (null !== $latest && $latest->matches($article)) {
            return;
        }

        $this->entityManager->persist(new NewsArticleRevision(
            $article,
            null === $latest ? 1 : $latest->getVersion() + 1,
            $editor,
        ));
    }
}
