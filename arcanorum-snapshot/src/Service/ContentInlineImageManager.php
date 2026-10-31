<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ContentCategory;
use App\Entity\ContentCategoryType;
use App\Entity\ContentInlineImage;
use App\Entity\NewsArticle;
use App\Entity\PrivateObjectDeletionJob;
use App\Entity\StaticPage;
use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Repository\ContentCategoryRepository;
use App\Repository\ContentInlineImageRepository;
use App\Repository\NewsArticleRepository;
use App\Repository\PrivateObjectDeletionJobRepository;
use App\Repository\StaticPageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class ContentInlineImageManager
{
    private const int MAX_IMAGES_PER_CONTENT = 20;
    private const int MAX_TOTAL_BYTES_PER_CONTENT = 20_971_520;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private NewsArticleRepository $articles,
        private StaticPageRepository $pages,
        private ContentCategoryRepository $categories,
        private ContentInlineImageRepository $images,
        private PrivateObjectDeletionJobRepository $deletionJobs,
        private ContentInlineImageUploadService $uploads,
        private PrivateObjectStorageInterface $storage,
        private LoggerInterface $logger,
    ) {
    }

    public function uploadForNewsArticle(string $articleId, UploadedFile $upload): ContentInlineImage
    {
        $storedObjectId = null;

        try {
            return $this->entityManager->getConnection()->transactional(function () use ($articleId, $upload, &$storedObjectId): ContentInlineImage {
                $article = $this->articles->findByIdForUpdate($articleId);
                if (!$article instanceof NewsArticle) {
                    throw new NotFoundHttpException('News-Beitrag wurde nicht gefunden.');
                }

                $usage = $this->images->usageForNewsArticle($articleId);
                if ($usage['count'] >= self::MAX_IMAGES_PER_CONTENT || $usage['bytes'] >= self::MAX_TOTAL_BYTES_PER_CONTENT) {
                    throw new \InvalidArgumentException('Pro Beitrag sind höchstens 20 Bilder und insgesamt 20 MiB zulässig.');
                }

                $stored = $this->uploads->store($upload);
                $storedObjectId = $stored['object_id'];
                if ($usage['bytes'] + $stored['size'] > self::MAX_TOTAL_BYTES_PER_CONTENT) {
                    throw new \InvalidArgumentException('Die Bilder dieses Beitrags dürfen zusammen höchstens 20 MiB groß sein.');
                }

                $image = new ContentInlineImage(
                    $stored['object_id'],
                    $article,
                    null,
                    $stored['mime_type'],
                    $stored['size'],
                    $stored['sha256'],
                );
                $this->entityManager->persist($image);
                $this->entityManager->flush();

                return $image;
            });
        } catch (\Throwable $exception) {
            if (null !== $storedObjectId) {
                $this->cleanupAfterFailedPersistence($storedObjectId);
            }

            throw $exception;
        }
    }

    public function uploadForStaticPage(string $pageId, UploadedFile $upload): ContentInlineImage
    {
        $storedObjectId = null;

        try {
            return $this->entityManager->getConnection()->transactional(function () use ($pageId, $upload, &$storedObjectId): ContentInlineImage {
                $page = $this->pages->findByIdForUpdate($pageId);
                if (!$page instanceof StaticPage) {
                    throw new NotFoundHttpException('Seite wurde nicht gefunden.');
                }

                $usage = $this->images->usageForStaticPage($pageId);
                if ($usage['count'] >= self::MAX_IMAGES_PER_CONTENT || $usage['bytes'] >= self::MAX_TOTAL_BYTES_PER_CONTENT) {
                    throw new \InvalidArgumentException('Pro Seite sind höchstens 20 Bilder und insgesamt 20 MiB zulässig.');
                }

                $stored = $this->uploads->store($upload);
                $storedObjectId = $stored['object_id'];
                if ($usage['bytes'] + $stored['size'] > self::MAX_TOTAL_BYTES_PER_CONTENT) {
                    throw new \InvalidArgumentException('Die Bilder dieser Seite dürfen zusammen höchstens 20 MiB groß sein.');
                }

                $image = new ContentInlineImage(
                    $stored['object_id'],
                    null,
                    $page,
                    $stored['mime_type'],
                    $stored['size'],
                    $stored['sha256'],
                );
                $this->entityManager->persist($image);
                $this->entityManager->flush();

                return $image;
            });
        } catch (\Throwable $exception) {
            if (null !== $storedObjectId) {
                $this->cleanupAfterFailedPersistence($storedObjectId);
            }

            throw $exception;
        }
    }

    public function uploadForCategory(string $categoryId, ContentCategoryType $type, UploadedFile $upload): ContentInlineImage
    {
        $storedObjectId = null;

        try {
            return $this->entityManager->getConnection()->transactional(function () use ($categoryId, $type, $upload, &$storedObjectId): ContentInlineImage {
                $category = $this->categories->findForUpdate($categoryId);
                if (!$category instanceof ContentCategory || $category->getContentType() !== $type) {
                    throw new NotFoundHttpException('Kategorie wurde nicht gefunden.');
                }

                $usage = $this->images->usageForCategory($categoryId);
                if ($usage['count'] >= self::MAX_IMAGES_PER_CONTENT || $usage['bytes'] >= self::MAX_TOTAL_BYTES_PER_CONTENT) {
                    throw new \InvalidArgumentException('Pro Kategorie sind höchstens 20 Bilder und insgesamt 20 MiB zulässig.');
                }

                $stored = $this->uploads->store($upload);
                $storedObjectId = $stored['object_id'];
                if ($usage['bytes'] + $stored['size'] > self::MAX_TOTAL_BYTES_PER_CONTENT) {
                    throw new \InvalidArgumentException('Die Bilder dieser Kategorie dürfen zusammen höchstens 20 MiB groß sein.');
                }

                $image = new ContentInlineImage(
                    $stored['object_id'],
                    null,
                    null,
                    $stored['mime_type'],
                    $stored['size'],
                    $stored['sha256'],
                    $category,
                );
                $this->entityManager->persist($image);
                $this->entityManager->flush();

                return $image;
            });
        } catch (\Throwable $exception) {
            if (null !== $storedObjectId) {
                $this->cleanupAfterFailedPersistence($storedObjectId);
            }

            throw $exception;
        }
    }

    /** @param list<string> $currentImageIds */
    public function removeUnusedPageImages(StaticPage $page, array $currentImageIds): void
    {
        $used = array_fill_keys($currentImageIds, true);
        foreach ($this->images->findForStaticPage($page->getId()) as $image) {
            if (isset($used[$image->getId()])) {
                continue;
            }

            $this->entityManager->remove($image);
            $this->queueObjectDeletion($image->getId());
        }
    }

    /** @param list<string> $currentImageIds */
    public function removeUnusedNewsImages(NewsArticle $article, array $currentImageIds): void
    {
        $used = array_fill_keys($currentImageIds, true);
        foreach ($this->images->findForNewsArticle($article->getId()) as $image) {
            if (isset($used[$image->getId()])
                || $this->images->isReferencedInNewsHistory($article->getId(), $image->getId())
            ) {
                continue;
            }

            $this->entityManager->remove($image);
            $this->queueObjectDeletion($image->getId());
        }
    }

    /** @param list<string> $currentImageIds */
    public function removeUnusedCategoryImages(ContentCategory $category, array $currentImageIds): void
    {
        $used = array_fill_keys($currentImageIds, true);
        foreach ($this->images->findForCategory($category->getId()) as $image) {
            if (isset($used[$image->getId()])) {
                continue;
            }

            $this->entityManager->remove($image);
            $this->queueObjectDeletion($image->getId());
        }
    }

    public function removeAllCategoryImages(ContentCategory $category): void
    {
        foreach ($this->images->findForCategory($category->getId()) as $image) {
            $this->entityManager->remove($image);
            $this->queueObjectDeletion($image->getId());
        }
    }

    private function cleanupAfterFailedPersistence(string $objectId): void
    {
        try {
            $this->storage->delete($objectId);
        } catch (\Throwable $cleanupError) {
            try {
                if (!$this->deletionJobs->hasObject($objectId)) {
                    $this->entityManager->persist(new PrivateObjectDeletionJob($objectId));
                    $this->entityManager->flush();
                }
            } catch (\Throwable $queueError) {
                $this->logger->critical('Ein nicht zugeordnetes Inline-Bild konnte nicht bereinigt oder zur Bereinigung vorgemerkt werden.', [
                    'cleanup_error' => $cleanupError::class,
                    'queue_error' => $queueError::class,
                ]);
            }
        }
    }

    private function queueObjectDeletion(string $objectId): void
    {
        if (!$this->deletionJobs->hasObject($objectId)) {
            $this->entityManager->persist(new PrivateObjectDeletionJob($objectId));
        }
    }
}
