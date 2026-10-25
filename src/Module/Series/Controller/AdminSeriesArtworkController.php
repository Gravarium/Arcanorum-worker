<?php

declare(strict_types=1);

namespace App\Module\Series\Controller;

use App\Entity\ContentSeries;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use App\Repository\ContentSeriesRepository;
use App\Service\SeriesArtworkUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/series', name: 'admin_series_artwork_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminSeriesArtworkController extends AbstractController
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    #[Route(
        '/{id}/artwork',
        name: 'manage',
        requirements: ['id' => '[a-f0-9]{32}'],
        methods: ['POST'],
    )]
    public function manage(
        string $id,
        Request $request,
        ContentSeriesRepository $series,
        SeriesArtworkUploadService $artworks,
        EntityManagerInterface $entityManager,
    ): Response {
        $item = $series->find($id);
        if (!$item instanceof ContentSeries) {
            throw $this->createNotFoundException($this->translator->trans('Serie wurde nicht gefunden.'));
        }

        $token = self::input($request->request, '_token');
        if ('' === $token || !$this->isCsrfTokenValid('admin_series_artwork_'.$item->getId(), $token)) {
            throw $this->createAccessDeniedException();
        }

        $action = self::input($request->request, 'action');
        if ('remove' === $action) {
            if (null !== $request->files->get('artwork_file')) {
                $this->addFlash('error', $this->translator->trans('Beim Entfernen darf keine Datei mitgesendet werden.'));

                return $this->redirectToRoute('admin_series_edit', ['id' => $item->getId()]);
            }

            $previousObjectId = $item->removeArtwork();
            if (null === $previousObjectId) {
                $this->addFlash('error', $this->translator->trans('Für diese Serie ist kein Bild gespeichert.'));

                return $this->redirectToRoute('admin_series_edit', ['id' => $item->getId()]);
            }

            $entityManager->flush();
            try {
                $artworks->delete($previousObjectId);
            } catch (PrivateStorageOperationFailed|\InvalidArgumentException) {
                $this->addFlash(
                    'warning',
                    $this->translator->trans('Das Serienbild wurde entfernt, die private Datei konnte aber nicht bereinigt werden.'),
                );
            }
            $this->addFlash('success', $this->translator->trans('Serienbild wurde entfernt.'));

            return $this->redirectToRoute('admin_series_edit', ['id' => $item->getId()]);
        }

        if ('upload' !== $action) {
            $this->addFlash('error', $this->translator->trans('Bitte wähle eine gültige Serienbildaktion aus.'));

            return $this->redirectToRoute('admin_series_edit', ['id' => $item->getId()]);
        }

        $uploadField = $request->files->get('artwork_file');
        if (!$uploadField instanceof UploadedFile) {
            $this->addFlash('error', $this->translator->trans('Bitte wähle eine gültige Bilddatei aus.'));

            return $this->redirectToRoute('admin_series_edit', ['id' => $item->getId()]);
        }

        try {
            $artwork = $artworks->store($uploadField);
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $this->translator->trans($exception->getMessage()));

            return $this->redirectToRoute('admin_series_edit', ['id' => $item->getId()]);
        } catch (PrivateStorageOperationFailed) {
            $this->addFlash(
                'error',
                $this->translator->trans('Der sichere private Dateispeicher ist derzeit nicht verfügbar; der Upload wurde gesperrt.'),
            );

            return $this->redirectToRoute('admin_series_edit', ['id' => $item->getId()]);
        }

        $previousObjectId = $item->getArtworkObjectId();
        $item->attachArtwork($artwork['object_id'], $artwork['mime_type'], $artwork['size']);
        try {
            $entityManager->flush();
        } catch (\Throwable $exception) {
            try {
                $artworks->delete($artwork['object_id']);
            } catch (PrivateStorageOperationFailed|\InvalidArgumentException) {
                $this->addFlash(
                    'warning',
                    $this->translator->trans('Ein neuer privater Upload konnte nach dem Speicherfehler nicht bereinigt werden.'),
                );
            }

            throw $exception;
        }

        if (null !== $previousObjectId && $previousObjectId !== $artwork['object_id']) {
            try {
                $artworks->delete($previousObjectId);
            } catch (PrivateStorageOperationFailed|\InvalidArgumentException) {
                $this->addFlash('warning', $this->translator->trans('Das frühere private Serienbild konnte nicht bereinigt werden.'));
            }
        }

        $this->addFlash('success', $this->translator->trans('Serienbild wurde gespeichert.'));

        return $this->redirectToRoute('admin_series_edit', ['id' => $item->getId()]);
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
