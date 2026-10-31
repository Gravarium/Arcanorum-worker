<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PrivateObjectDeletionJob;
use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use App\Repository\PrivateObjectDeletionJobRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PrivateObjectDeletionService
{
    private const int MAX_BATCH_SIZE = 100;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PrivateObjectDeletionJobRepository $jobs,
        private PrivateObjectStorageInterface $storage,
    ) {
    }

    /** @return array{deleted: int, failed: int, pending: int} */
    public function processDue(int $limit = 25): array
    {
        if ($limit < 1 || $limit > self::MAX_BATCH_SIZE) {
            throw new \InvalidArgumentException('Die Bereinigungsgröße muss zwischen 1 und 100 liegen.');
        }

        $deleted = 0;
        $failed = 0;
        for ($processed = 0; $processed < $limit; ++$processed) {
            $result = $this->entityManager->getConnection()->transactional(function (): ?bool {
                $job = $this->jobs->findDueForUpdate(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
                if (!$job instanceof PrivateObjectDeletionJob) {
                    return null;
                }

                try {
                    $this->storage->delete($job->getObjectId());
                } catch (PrivateStorageOperationFailed|\InvalidArgumentException) {
                    $job->recordFailure(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
                    $this->entityManager->flush();

                    return false;
                }

                $this->entityManager->remove($job);
                $this->entityManager->flush();

                return true;
            });

            if (null === $result) {
                break;
            }
            if ($result) {
                ++$deleted;
            } else {
                ++$failed;
            }
        }

        return ['deleted' => $deleted, 'failed' => $failed, 'pending' => $this->jobs->countPending()];
    }
}
