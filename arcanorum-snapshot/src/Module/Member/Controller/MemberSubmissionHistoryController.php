<?php

declare(strict_types=1);

namespace App\Module\Member\Controller;

use App\Entity\CommentContentType;
use App\Entity\ContentCategoryType;
use App\Entity\MemberAccount;
use App\Entity\PublishingStatus;
use App\Entity\VideoSubmission;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use App\Repository\ContentCategoryRepository;
use App\Repository\ContentCommentRepository;
use App\Repository\VideoSubmissionRepository;
use App\Repository\VideoSubtitleTrackRepository;
use App\Service\VideoMediaUploadService;
use App\Service\VideoSubtitleUploadService;
use App\Service\VideoThumbnailUploadService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MemberSubmissionHistoryController extends AbstractController
{
    #[Route('/konto/einreichungen', name: 'member_submission_history', methods: ['GET'])]
    public function index(VideoSubmissionRepository $submissions): Response
    {
        $member = $this->requireMember();
        $response = $this->render('member/submissions.html.twig', [
            'member' => $member,
            'submissions' => $submissions->findForSubmitter($member),
        ]);
        $response->headers->add(self::privateHeaders());

        return $response;
    }

    #[Route(
        '/konto/einreichungen/{id}/bearbeiten',
        name: 'member_submission_edit',
        requirements: ['id' => '[a-f0-9]{32}'],
        methods: ['GET', 'POST'],
    )]
    public function edit(
        string $id,
        Request $request,
        VideoSubmissionRepository $submissions,
        ContentCategoryRepository $categories,
        EntityManagerInterface $entityManager,
    ): Response {
        $member = $this->requireMember();
        $submission = $submissions->findForSubmitterById($member, $id);
        if (null === $submission) {
            return new Response('', Response::HTTP_NOT_FOUND, self::privateHeaders());
        }
        if (!$this->isEditable($submission)) {
            $this->addFlash('error', 'Freigegebene Einreichungen können nicht mehr bearbeitet werden.');

            return $this->privateRedirectToHistory();
        }

        $video = $submission->getVideo();
        $values = [
            'title' => $video->getTitle(),
            'description' => $video->getDescription(),
            'category' => $video->getCategory() ?? '',
        ];
        $errors = [];
        $status = Response::HTTP_OK;

        if ($request->isMethod('POST')) {
            $payload = $request->request->all();
            $allowedFields = ['_token', 'title', 'description', 'category'];
            $hasInvalidFields = [] !== array_diff(array_keys($payload), $allowedFields);
            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $payload) && !is_string($payload[$field])) {
                    $hasInvalidFields = true;
                }
            }
            $token = $payload['_token'] ?? null;
            if (!is_string($token) || '' === $token || !$this->isCsrfTokenValid('member_submission_edit_'.$submission->getId(), $token)) {
                return new Response('', Response::HTTP_FORBIDDEN, self::privateHeaders());
            }

            $values = [
                'title' => self::stringInput($payload, 'title'),
                'description' => self::stringInput($payload, 'description'),
                'category' => self::stringInput($payload, 'category'),
            ];
            if ($hasInvalidFields) {
                $errors[] = 'Die Anfrage enthält ungültige oder unbekannte Eingabefelder.';
            }
            if (1 !== preg_match('//u', $values['title'])
                || 1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $values['title'])
                || '' === $values['title']
                || mb_strlen($values['title'], 'UTF-8') > 180
            ) {
                $errors[] = 'Der Titel muss 1 bis 180 Zeichen lang sein und gültiges UTF-8 enthalten.';
            }
            if (1 !== preg_match('//u', $values['description'])
                || 1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $values['description'])
                || mb_strlen($values['description'], 'UTF-8') > 20000
            ) {
                $errors[] = 'Die Beschreibung darf höchstens 20.000 Zeichen lang sein und keine unzulässigen Steuerzeichen enthalten.';
            }
            if (mb_strlen($values['category'], 'UTF-8') > 100
                || ('' !== $values['category']
                    && null === $categories->findForTypeAndName(ContentCategoryType::Video, $values['category']))
            ) {
                $errors[] = 'Bitte wähle eine vorhandene Videokategorie.';
            }

            if ([] !== $errors) {
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            } else {
                $updated = $entityManager->getConnection()->transactional(function (Connection $connection) use (
                    $entityManager,
                    $member,
                    $submission,
                    $values,
                    $video,
                ): bool {
                    $videoStatus = $connection->fetchOne(
                        'SELECT status FROM videos WHERE id = :video_id FOR UPDATE',
                        ['video_id' => $video->getId()],
                    );
                    $status = $connection->fetchOne(
                        'SELECT status FROM video_submissions WHERE id = :id AND submitter_id = :submitter_id FOR UPDATE',
                        ['id' => $submission->getId(), 'submitter_id' => $member->getId()],
                    );
                    if ('draft' !== $videoStatus || !in_array($status, ['pending', 'rejected'], true)) {
                        return false;
                    }

                    $entityManager->refresh($video);
                    $entityManager->refresh($submission);

                    $video->revise(
                        $values['title'],
                        $values['description'],
                        '' === $values['category'] ? null : $values['category'],
                        $video->getSourceType(),
                        $video->getSourceUrl(),
                        PublishingStatus::Draft,
                        false,
                        false,
                    );
                    $submission->revise();
                    $entityManager->flush();

                    return true;
                });
                if (!$updated) {
                    $this->addFlash('error', 'Diese Einreichung wurde zwischenzeitlich freigegeben und kann nicht mehr bearbeitet werden.');

                    return $this->privateRedirectToHistory();
                }

                $this->addFlash('success', 'Deine Änderungen wurden gespeichert und zur Prüfung vorgemerkt.');

                return $this->privateRedirectToHistory();
            }
        }

        $response = $this->render('member/submission_edit.html.twig', [
            'member' => $member,
            'submission' => $submission,
            'categories' => $categories->findForAdmin(ContentCategoryType::Video),
            'values' => $values,
            'errors' => $errors,
        ], new Response(status: $status));
        $response->headers->add(self::privateHeaders());

        return $response;
    }

    #[Route(
        '/konto/einreichungen/{id}/loeschen',
        name: 'member_submission_delete',
        requirements: ['id' => '[a-f0-9]{32}'],
        methods: ['POST'],
    )]
    public function delete(
        string $id,
        Request $request,
        VideoSubmissionRepository $submissions,
        ContentCommentRepository $comments,
        VideoSubtitleTrackRepository $subtitleTracks,
        EntityManagerInterface $entityManager,
        VideoMediaUploadService $mediaUploads,
        VideoThumbnailUploadService $thumbnailUploads,
        VideoSubtitleUploadService $subtitleUploads,
    ): Response {
        $member = $this->requireMember();
        $submission = $submissions->findForSubmitterById($member, $id);
        if (null === $submission) {
            return new Response('', Response::HTTP_NOT_FOUND, self::privateHeaders());
        }
        $payload = $request->request->all();
        if ([] !== array_diff(array_keys($payload), ['_token'])) {
            return new Response('', Response::HTTP_BAD_REQUEST, self::privateHeaders());
        }
        $token = $payload['_token'] ?? null;
        if (!is_string($token) || '' === $token || !$this->isCsrfTokenValid('member_submission_delete_'.$submission->getId(), $token)) {
            return new Response('', Response::HTTP_FORBIDDEN, self::privateHeaders());
        }
        if (!$this->isEditable($submission)) {
            $this->addFlash('error', 'Freigegebene Videos können nicht über die Einreichung gelöscht werden.');

            return $this->privateRedirectToHistory();
        }

        $video = $submission->getVideo();
        /** @var array{uploaded_object_id: ?string, thumbnail_object_id: ?string, subtitle_object_ids: list<string>}|false $deletedContent */
        $deletedContent = $entityManager->getConnection()->transactional(function (Connection $connection) use (
            $comments,
            $entityManager,
            $member,
            $submission,
            $subtitleTracks,
            $video,
        ): array|false {
            $videoStatus = $connection->fetchOne(
                'SELECT status FROM videos WHERE id = :video_id FOR UPDATE',
                ['video_id' => $video->getId()],
            );
            $status = $connection->fetchOne(
                'SELECT status FROM video_submissions WHERE id = :id AND submitter_id = :submitter_id FOR UPDATE',
                ['id' => $submission->getId(), 'submitter_id' => $member->getId()],
            );
            if ('draft' !== $videoStatus || !in_array($status, ['pending', 'rejected'], true)) {
                return false;
            }

            $entityManager->refresh($video);
            $entityManager->refresh($submission);
            $uploadedObjectId = $video->getUploadedObjectId();
            $thumbnailObjectId = $video->getThumbnailObjectId();
            $tracks = $subtitleTracks->findForVideo($video);
            $subtitleObjectIds = [];
            foreach ($tracks as $track) {
                $subtitleObjectIds[] = $track->getStorageObjectId();
            }

            foreach ($tracks as $track) {
                $entityManager->remove($track);
            }
            $comments->deleteForContent(CommentContentType::Video, $video->getId());
            $entityManager->remove($submission);
            $entityManager->remove($video);
            $entityManager->flush();

            return [
                'uploaded_object_id' => $uploadedObjectId,
                'thumbnail_object_id' => $thumbnailObjectId,
                'subtitle_object_ids' => $subtitleObjectIds,
            ];
        });
        if (false === $deletedContent) {
            $this->addFlash('error', 'Diese Einreichung wurde zwischenzeitlich freigegeben und kann nicht mehr gelöscht werden.');

            return $this->privateRedirectToHistory();
        }

        $cleanupFailed = false;
        if (null !== $deletedContent['uploaded_object_id']) {
            try {
                $mediaUploads->delete($deletedContent['uploaded_object_id']);
            } catch (PrivateStorageOperationFailed|\InvalidArgumentException) {
                $cleanupFailed = true;
            }
        }
        if (null !== $deletedContent['thumbnail_object_id']) {
            try {
                $thumbnailUploads->delete($deletedContent['thumbnail_object_id']);
            } catch (PrivateStorageOperationFailed|\InvalidArgumentException) {
                $cleanupFailed = true;
            }
        }
        foreach ($deletedContent['subtitle_object_ids'] as $objectId) {
            try {
                $subtitleUploads->delete($objectId);
            } catch (PrivateStorageOperationFailed|\InvalidArgumentException) {
                $cleanupFailed = true;
            }
        }

        $this->addFlash($cleanupFailed ? 'warning' : 'success', $cleanupFailed
            ? 'Die Einreichung wurde gelöscht; mindestens eine private Mediendatei konnte nicht bereinigt werden.'
            : 'Deine Einreichung wurde gelöscht.');

        return $this->privateRedirectToHistory();
    }

    private function requireMember(): MemberAccount
    {
        $member = $this->getUser();
        if (!$member instanceof MemberAccount) {
            throw $this->createAccessDeniedException();
        }

        return $member;
    }

    private function isEditable(VideoSubmission $submission): bool
    {
        return $submission->isEditableBySubmitter();
    }

    /** @param array<string, mixed> $payload */
    private static function stringInput(array $payload, string $key): string
    {
        $value = $payload[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    private function privateRedirectToHistory(): Response
    {
        $response = $this->redirectToRoute('member_submission_history');
        $response->headers->add(self::privateHeaders());

        return $response;
    }

    /** @return array<string, string> */
    private static function privateHeaders(): array
    {
        return [
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];
    }
}
