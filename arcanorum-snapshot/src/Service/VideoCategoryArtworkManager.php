<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ContentCategory;
use App\Entity\ContentCategoryType;
use App\Entity\PrivateObjectDeletionJob;
use App\Repository\ContentCategoryRepository;
use App\Repository\PrivateObjectDeletionJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class VideoCategoryArtworkManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ContentCategoryRepository $categories,
        private PrivateObjectDeletionJobRepository $deletionJobs,
        private VideoCategoryArtworkUploadService $uploads,
        private LoggerInterface $logger,
    ) {
    }

    public function replace(string $categoryId, UploadedFile $upload): ContentCategory
    {
        $newObjectId = null;
        $previousObjectId = null;

        try {
            $category = $this->entityManager->getConnection()->transactional(function () use (
                $categoryId,
                $upload,
                &$newObjectId,
                &$previousObjectId,
            ): ContentCategory {
                $category = $this->categories->findForUpdate($categoryId);
                if (!$category instanceof ContentCategory || ContentCategoryType::Video !== $category->getContentType()) {
                    throw new NotFoundHttpException('Videokategorie wurde nicht gefunden.');
                }

                $artwork = $this->uploads->store($upload);
                $newObjectId = $artwork['object_id'];
                $previousObjectId = $category->getArtworkObjectId();
                $category->attachArtwork(
                    $artwork['object_id'],
                    $artwork['mime_type'],
                    $artwork['size'],
                    $artwork['sha256'],
                );
                $this->entityManager->flush();

                return $category;
            });
        } catch (\Throwable $exception) {
            if (null !== $newObjectId) {
                $this->cleanupObject($newObjectId);
            }

            throw $exception;
        }

        if (null !== $previousObjectId && $previousObjectId !== $newObjectId) {
            $this->deleteOrQueue($previousObjectId);
        }

        return $category;
    }

    public function remove(string $categoryId): void
    {
        $objectId = $this->entityManager->getConnection()->transactional(function () use ($categoryId): ?string {
            $category = $this->categories->findForUpdate($categoryId);
            if (!$category instanceof ContentCategory || ContentCategoryType::Video !== $category->getContentType()) {
                throw new NotFoundHttpException('Videokategorie wurde nicht gefunden.');
            }

            $objectId = $category->getArtworkObjectId();
            $category->removeArtwork();
            $this->entityManager->flush();

            return $objectId;
        });

        if (null !== $objectId) {
            $this->deleteOrQueue($objectId);
        }
    }

    private function deleteOrQueue(string $objectId): void
    {
        try {
            $this->uploads->delete($objectId);
        } catch (\Throwable $storageError) {
            try {
                if (!$this->deletionJobs->hasObject($objectId)) {
                    $this->entityManager->persist(new PrivateObjectDeletionJob($objectId));
                    $this->entityManager->flush();
                }
            } catch (\Throwable $queueError) {
                $this->logger->critical('Ein Videokategorie-Artwork konnte weder gelöscht noch zur Bereinigung vorgemerkt werden.', [
                    'storage_error' => $storageError::class,
                    'queue_error' => $queueError::class,
                ]);
                throw new \RuntimeException('Das frühere Kategorie-Artwork konnte nicht sicher bereinigt werden.', 0, $queueError);
            }
        }
    }

    private function cleanupObject(string $objectId): void
    {
        try {
            $this->uploads->delete($objectId);
        } catch (\Throwable $storageError) {
            try {
                if (!$this->deletionJobs->hasObject($objectId)) {
                    $this->entityManager->persist(new PrivateObjectDeletionJob($objectId));
                    $this->entityManager->flush();
                }
            } catch (\Throwable $queueError) {
                $this->logger->critical('Ein nicht zugeordnetes Videokategorie-Artwork konnte nicht bereinigt oder zur Bereinigung vorgemerkt werden.', [
                    'storage_error' => $storageError::class,
                    'queue_error' => $queueError::class,
                ]);
            }
        }
    }
}
