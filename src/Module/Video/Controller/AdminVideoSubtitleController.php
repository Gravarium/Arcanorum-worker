<?php

declare(strict_types=1);

namespace App\Module\Video\Controller;

use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Entity\VideoSubtitleTrack;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use App\Repository\VideoRepository;
use App\Repository\VideoSubtitleTrackRepository;
use App\Service\VideoSubtitleUploadService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/videos/{id}/subtitles', name: 'admin_video_subtitle_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminVideoSubtitleController extends AbstractController
{
    #[Route('', name: 'upload', requirements: ['id' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function upload(
        string $id,
        Request $request,
        VideoRepository $videos,
        VideoSubtitleTrackRepository $tracks,
        VideoSubtitleUploadService $uploads,
        EntityManagerInterface $entityManager,
    ): Response {
        $video = $videos->find($id);
        if (!$video instanceof Video) {
            throw $this->createNotFoundException('Video wurde nicht gefunden.');
        }

        if (!$this->isCsrfTokenValid('admin_video_subtitles_upload_'.$video->getId(), self::input($request->request, '_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (!self::hasOnlyKeys($request->request->all(), ['_token', 'language_code', 'label'])
            || !self::hasOnlyKeys($request->files->all(), ['subtitle_file'])
        ) {
            $this->addFlash('error', 'Die Untertitelanfrage enthält nicht erlaubte Felder.');

            return $this->redirectToRoute('admin_video_edit', ['id' => $video->getId()]);
        }
        if (!in_array($video->getSourceType(), [VideoSourceType::File, VideoSourceType::Stream], true)) {
            $this->addFlash('error', 'Untertitel sind nur für private Videos und geprüfte Streams verfügbar.');

            return $this->redirectToRoute('admin_video_edit', ['id' => $video->getId()]);
        }

        $languageCode = trim(self::input($request->request, 'language_code'));
        $label = trim(self::input($request->request, 'label'));
        if (!VideoSubtitleTrack::isValidLanguageCode($languageCode)
            || !VideoSubtitleTrack::isValidLabel($label)
        ) {
            $this->addFlash('error', 'Bitte wähle einen gültigen Sprachcode und eine Beschriftung mit höchstens 120 Zeichen.');

            return $this->redirectToRoute('admin_video_edit', ['id' => $video->getId()]);
        }

        $uploadField = $request->files->get('subtitle_file');
        if (!$uploadField instanceof UploadedFile) {
            $this->addFlash('error', 'Bitte wähle genau eine WebVTT-Datei aus.');

            return $this->redirectToRoute('admin_video_edit', ['id' => $video->getId()]);
        }

        try {
            $stored = $uploads->store($uploadField);
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('admin_video_edit', ['id' => $video->getId()]);
        } catch (PrivateStorageOperationFailed) {
            $this->addFlash('error', 'Der sichere private Dateispeicher ist derzeit nicht verfügbar; der Upload wurde gesperrt.');

            return $this->redirectToRoute('admin_video_edit', ['id' => $video->getId()]);
        }

        $languageCode = VideoSubtitleTrack::normalizeLanguageCode($languageCode);
        try {
            $previousObjectId = $entityManager->getConnection()->transactional(function (Connection $connection) use (
                $video,
                $languageCode,
                $label,
                $stored,
                $tracks,
                $entityManager,
            ): ?string {
                $connection->executeQuery(
                    'SELECT pg_advisory_xact_lock(hashtextextended(:lock_key, 0))',
                    ['lock_key' => 'video-subtitle:'.$video->getId().':'.$languageCode],
                );
                $track = $tracks->findForVideoAndLanguage($video, $languageCode);
                if (null === $track) {
                    $track = new VideoSubtitleTrack(
                        $video,
                        $languageCode,
                        $label,
                        $stored['object_id'],
                        $stored['size'],
                        $stored['sha256'],
                    );
                    $previousObjectId = null;
                } else {
                    $previousObjectId = $track->revise(
                        $video,
                        $languageCode,
                        $label,
                        $stored['object_id'],
                        $stored['size'],
                        $stored['sha256'],
                    );
                }

                $entityManager->persist($track);
                $entityManager->flush();

                return $previousObjectId;
            });
        } catch (\Throwable $exception) {
            try {
                $uploads->delete($stored['object_id']);
            } catch (PrivateStorageOperationFailed|\InvalidArgumentException) {
                $this->addFlash('warning', 'Ein neuer privater Untertitel konnte nach dem Speicherfehler nicht bereinigt werden.');
            }

            throw $exception;
        }

        if (null !== $previousObjectId && $previousObjectId !== $stored['object_id']) {
            try {
                $uploads->delete($previousObjectId);
            } catch (PrivateStorageOperationFailed|\InvalidArgumentException) {
                $this->addFlash('warning', 'Der frühere private Untertitel konnte nicht bereinigt werden.');
            }
        }

        $this->addFlash('success', 'Untertitelspur wurde gespeichert.');

        return $this->redirectToRoute('admin_video_edit', ['id' => $video->getId()]);
    }

    #[Route('/{trackId}/delete', name: 'delete', requirements: ['id' => '[a-f0-9]{32}', 'trackId' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function delete(
        string $id,
        string $trackId,
        Request $request,
        VideoRepository $videos,
        VideoSubtitleTrackRepository $tracks,
        VideoSubtitleUploadService $uploads,
        EntityManagerInterface $entityManager,
    ): Response {
        $video = $videos->find($id);
        if (!$video instanceof Video) {
            throw $this->createNotFoundException('Video wurde nicht gefunden.');
        }
        $track = $tracks->findForVideoAndId($video, $trackId);
        if (!$track instanceof VideoSubtitleTrack) {
            throw $this->createNotFoundException('Untertitelspur wurde nicht gefunden.');
        }
        if (!$this->isCsrfTokenValid('admin_video_subtitles_delete_'.$track->getId(), self::input($request->request, '_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (!self::hasOnlyKeys($request->request->all(), ['_token', 'action'])
            || [] !== $request->files->all()
            || 'delete' !== self::input($request->request, 'action')
        ) {
            $this->addFlash('error', 'Die Löschanfrage für die Untertitelspur ist ungültig.');

            return $this->redirectToRoute('admin_video_edit', ['id' => $video->getId()]);
        }

        $objectId = $track->getStorageObjectId();
        $entityManager->remove($track);
        $entityManager->flush();
        try {
            $uploads->delete($objectId);
        } catch (PrivateStorageOperationFailed|\InvalidArgumentException) {
            $this->addFlash('warning', 'Die Untertitelspur wurde entfernt, die private Datei konnte aber nicht bereinigt werden.');
        }
        $this->addFlash('success', 'Untertitelspur wurde entfernt.');

        return $this->redirectToRoute('admin_video_edit', ['id' => $video->getId()]);
    }

    /**
     * @template TInput of string|int|float|bool|null
     * @param InputBag<TInput> $bag
     */
    private static function input(InputBag $bag, string $key): string
    {
        $value = $bag->all()[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /** @param array<string, mixed> $input @param list<string> $allowed */
    private static function hasOnlyKeys(array $input, array $allowed): bool
    {
        return [] === array_diff(array_keys($input), $allowed);
    }
}
