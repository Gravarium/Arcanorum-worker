<?php

declare(strict_types=1);

namespace App\Module\Video\Controller;

use App\Entity\Video;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use App\Repository\VideoRepository;
use App\Service\VideoThumbnailUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/videos', name: 'admin_video_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminVideoThumbnailController extends AbstractController
{
    #[Route('/{id}/thumbnail', name: 'thumbnail_manage', requirements: ['id' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function manage(
        string $id,
        Request $request,
        VideoRepository $videos,
        VideoThumbnailUploadService $thumbnails,
        EntityManagerInterface $entityManager,
    ): Response {
        $video = $videos->find($id);
        if (!$video instanceof Video) {
            throw $this->createNotFoundException('Video wurde nicht gefunden.');
        }

        $token = self::input($request->request, '_token');
        if ('' === $token || !$this->isCsrfTokenValid('admin_video_thumbnail_'.$video->getId(), $token)) {
            throw $this->createAccessDeniedException();
        }

        $action = self::input($request->request, 'action');
        if ('remove' === $action) {
            if (null !== $request->files->get('thumbnail_file')) {
                $this->addFlash('error', 'Beim Entfernen darf keine Datei mitgesendet werden.');

                return $this->redirectToRoute('admin_video_edit', ['id' => $video->getId()]);
            }

            $previousObjectId = $video->removeThumbnail();
            if (null === $previousObjectId) {
                $this->addFlash('error', 'Für dieses Video ist kein Vorschaubild gespeichert.');

                return $this->redirectToRoute('admin_video_edit', ['id' => $video->getId()]);
            }

            $entityManager->flush();
            try {
                $thumbnails->delete($previousObjectId);
            } catch (PrivateStorageOperationFailed|\InvalidArgumentException) {
                $this->addFlash('warning', 'Das Vorschaubild wurde entfernt, die private Datei konnte aber nicht bereinigt werden.');
            }
            $this->addFlash('success', 'Vorschaubild wurde entfernt.');

            return $this->redirectToRoute('admin_video_edit', ['id' => $video->getId()]);
        }

        if ('upload' !== $action) {
            $this->addFlash('error', 'Bitte wähle eine gültige Vorschaubildaktion aus.');

            return $this->redirectToRoute('admin_video_edit', ['id' => $video->getId()]);
        }

        $uploadField = $request->files->get('thumbnail_file');
        if (!$uploadField instanceof UploadedFile) {
            $this->addFlash('error', 'Bitte wähle eine gültige Bilddatei aus.');

            return $this->redirectToRoute('admin_video_edit', ['id' => $video->getId()]);
        }

        try {
            $thumbnail = $thumbnails->store($uploadField);
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('admin_video_edit', ['id' => $video->getId()]);
        } catch (PrivateStorageOperationFailed) {
            $this->addFlash('error', 'Der sichere private Dateispeicher ist derzeit nicht verfügbar; der Upload wurde gesperrt.');

            return $this->redirectToRoute('admin_video_edit', ['id' => $video->getId()]);
        }

        $previousObjectId = $video->getThumbnailObjectId();
        $video->attachThumbnail($thumbnail['object_id'], $thumbnail['mime_type'], $thumbnail['size']);
        try {
            $entityManager->flush();
        } catch (\Throwable $exception) {
            try {
                $thumbnails->delete($thumbnail['object_id']);
            } catch (PrivateStorageOperationFailed|\InvalidArgumentException) {
                $this->addFlash('warning', 'Ein neuer privater Upload konnte nach dem Speicherfehler nicht bereinigt werden.');
            }

            throw $exception;
        }

        if (null !== $previousObjectId && $previousObjectId !== $thumbnail['object_id']) {
            try {
                $thumbnails->delete($previousObjectId);
            } catch (PrivateStorageOperationFailed|\InvalidArgumentException) {
                $this->addFlash('warning', 'Das frühere private Vorschaubild konnte nicht bereinigt werden.');
            }
        }

        $this->addFlash('success', 'Vorschaubild wurde gespeichert.');

        return $this->redirectToRoute('admin_video_edit', ['id' => $video->getId()]);
    }

    /**
     * @template TInput of string|int|float|bool|null
     *
     * @param InputBag<TInput> $bag
     */
    private static function input(InputBag $bag, string $key): string
    {
        $value = $bag->get($key);

        return is_string($value) ? $value : '';
    }
}
