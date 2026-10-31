<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CommentContentType;
use App\Entity\MemberAccount;
use App\Entity\PrivateObjectDeletionJob;
use App\Entity\VideoSubmissionStatus;
use App\Repository\ContentCommentRepository;
use App\Repository\PrivateObjectDeletionJobRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class MemberAccountDeletionService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ContentCommentRepository $comments,
        private PrivateObjectDeletionJobRepository $deletionJobs,
        private PrivateObjectDeletionService $privateObjects,
    ) {
    }

    public function delete(MemberAccount $account): MemberAccountDeletionResult
    {
        /** @var array<string, true> $queuedObjects */
        $queuedObjects = [];
        $deleted = $this->entityManager->getConnection()->transactional(function (Connection $connection) use ($account, &$queuedObjects): bool {
            $lockedMember = $connection->fetchOne(
                'SELECT id FROM member_accounts WHERE id = :id FOR UPDATE',
                ['id' => $account->getId()],
            );
            if (!is_string($lockedMember)) {
                return false;
            }

            $submissions = $connection->fetchAllAssociative(
                'SELECT submission.id AS submission_id, submission.status AS submission_status, '
                    .'video.id AS video_id, video.status AS video_status '
                    .'FROM video_submissions submission '
                    .'INNER JOIN videos video ON video.id = submission.video_id '
                    .'WHERE submission.submitter_id = :member_id '
                    .'ORDER BY submission.id ASC FOR UPDATE OF submission, video',
                ['member_id' => $account->getId()],
            );

            foreach ($submissions as $submission) {
                $submissionStatus = self::requiredString($submission, 'submission_status');
                $videoStatus = self::requiredString($submission, 'video_status');
                if (!in_array($submissionStatus, [VideoSubmissionStatus::Pending->value, VideoSubmissionStatus::Rejected->value], true)
                    || 'draft' !== $videoStatus
                ) {
                    continue;
                }

                $videoId = self::requiredString($submission, 'video_id');
                $assets = $connection->fetchAssociative(
                    'SELECT uploaded_object_id, thumbnail_object_id FROM videos WHERE id = :video_id',
                    ['video_id' => $videoId],
                );
                if (!is_array($assets)) {
                    throw new \LogicException('Eine gesperrte Videoeinreichung besitzt kein Video.');
                }

                self::queueNullableObject($assets['uploaded_object_id'] ?? null, $queuedObjects, $this->entityManager, $this->deletionJobs);
                $thumbnailObjectId = $assets['thumbnail_object_id'] ?? null;
                if (null !== $thumbnailObjectId
                    && (!is_string($thumbnailObjectId) || 1 !== preg_match('/\A[a-f0-9]{64}\z/D', $thumbnailObjectId))
                ) {
                    throw new \LogicException('Eine Kennung für ein privates Vorschaubild ist ungültig.');
                }
                self::queueNullableObject($thumbnailObjectId, $queuedObjects, $this->entityManager, $this->deletionJobs);
                if (null !== $thumbnailObjectId) {
                    self::queueNullableObject(
                        VideoSocialPreviewGenerator::cacheObjectIdForSourceObjectId($thumbnailObjectId),
                        $queuedObjects,
                        $this->entityManager,
                        $this->deletionJobs,
                    );
                }

                $subtitleObjects = $connection->fetchFirstColumn(
                    'SELECT storage_object_id FROM video_subtitle_tracks WHERE video_id = :video_id ORDER BY id ASC FOR UPDATE',
                    ['video_id' => $videoId],
                );
                foreach ($subtitleObjects as $objectId) {
                    self::queueNullableObject($objectId, $queuedObjects, $this->entityManager, $this->deletionJobs);
                }

                $this->comments->deleteForContent(CommentContentType::Video, $videoId);
                $connection->executeStatement('DELETE FROM videos WHERE id = :video_id', ['video_id' => $videoId]);
            }

            $connection->executeStatement(
                "UPDATE content_comments SET author_name = 'Gelöschtes Mitglied', filtered_by_term = NULL "
                    .'WHERE member_id = :member_id AND status = :approved_status',
                ['member_id' => $account->getId(), 'approved_status' => 'approved'],
            );
            $connection->executeStatement(
                'DELETE FROM content_comments WHERE member_id = :member_id AND status <> :approved_status',
                ['member_id' => $account->getId(), 'approved_status' => 'approved'],
            );

            $this->entityManager->remove($account);
            $this->entityManager->flush();

            return true;
        });

        if (!$deleted) {
            return new MemberAccountDeletionResult(false, 0);
        }

        $this->privateObjects->processDue();

        return new MemberAccountDeletionResult(true, $this->deletionJobs->countPendingForObjects(array_keys($queuedObjects)));
    }

    /** @param array<string, mixed> $row */
    private static function requiredString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || '' === $value) {
            throw new \LogicException('Eine erforderliche Videoeinreichungsangabe fehlt.');
        }

        return $value;
    }

    /** @param array<string, true> $queuedObjects */
    private static function queueNullableObject(
        mixed $objectId,
        array &$queuedObjects,
        EntityManagerInterface $entityManager,
        PrivateObjectDeletionJobRepository $jobs,
    ): void {
        if (null === $objectId) {
            return;
        }
        if (!is_string($objectId)) {
            throw new \LogicException('Eine Kennung für ein privates Speicherobjekt ist ungültig.');
        }
        if (isset($queuedObjects[$objectId]) || $jobs->hasObject($objectId)) {
            $queuedObjects[$objectId] = true;

            return;
        }

        $entityManager->persist(new PrivateObjectDeletionJob($objectId));
        $queuedObjects[$objectId] = true;
    }
}
