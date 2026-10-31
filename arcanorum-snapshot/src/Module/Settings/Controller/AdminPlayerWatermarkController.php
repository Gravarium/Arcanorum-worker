<?php

declare(strict_types=1);

namespace App\Module\Settings\Controller;

use App\Entity\SiteSettings;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use App\Repository\SiteSettingsRepository;
use App\Service\VideoPlayerWatermarkUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/settings/content/watermark', name: 'admin_content_watermark_')]
final class AdminPlayerWatermarkController extends AbstractController
{
    #[Route('', name: 'upload', methods: ['POST'])]
    public function upload(
        Request $request,
        SiteSettingsRepository $settingsRepository,
        VideoPlayerWatermarkUploadService $watermarks,
        EntityManagerInterface $entityManager,
        LoggerInterface $logger,
    ): Response {
        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid('admin_player_watermark', $token)) {
            return $this->privateResponse(new Response('', Response::HTTP_FORBIDDEN));
        }
        if ([] !== array_diff(array_keys($request->request->all()), ['_token'])
            || [] !== array_diff(array_keys($request->files->all()), ['watermark_file'])
        ) {
            $this->addFlash('error', 'Die Wasserzeichen-Anfrage enthält unbekannte Felder.');

            return $this->privateResponse($this->redirectToRoute('admin_content_settings_index'));
        }
        $upload = $request->files->get('watermark_file');
        if (!$upload instanceof UploadedFile) {
            $this->addFlash('error', 'Bitte wähle eine Wasserzeichen-Datei aus.');

            return $this->privateResponse($this->redirectToRoute('admin_content_settings_index'));
        }

        try {
            $asset = $watermarks->store($upload);
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->privateResponse($this->redirectToRoute('admin_content_settings_index'));
        } catch (PrivateStorageOperationFailed) {
            $this->addFlash('error', 'Der sichere private Dateispeicher ist derzeit nicht verfügbar; der Upload wurde gesperrt.');

            return $this->privateResponse($this->redirectToRoute('admin_content_settings_index'));
        }

        $settings = $settingsRepository->findCurrent();
        $isNew = null === $settings;
        $settings ??= new SiteSettings();
        $previousObjectId = $settings->getVideoPlayerWatermarkObjectId();
        $settings->attachVideoPlayerWatermark($asset['object_id'], $asset['mime_type'], $asset['size'], $asset['sha256']);
        if ($isNew) {
            $entityManager->persist($settings);
        }

        try {
            $entityManager->flush();
        } catch (\Throwable $exception) {
            $logger->error('Player-Wasserzeichen konnte nicht gespeichert werden.', ['failure_type' => $exception::class]);
            try {
                $watermarks->delete($asset['object_id']);
            } catch (\Throwable $cleanupException) {
                $logger->error('Neues Player-Wasserzeichen konnte nach einem Datenbankfehler nicht bereinigt werden.', [
                    'failure_type' => $cleanupException::class,
                ]);
            }
            $this->addFlash('error', 'Das Wasserzeichen konnte nicht gespeichert werden.');

            return $this->privateResponse($this->redirectToRoute('admin_content_settings_index'));
        }

        if (null !== $previousObjectId && $previousObjectId !== $asset['object_id']) {
            try {
                $watermarks->delete($previousObjectId);
            } catch (PrivateStorageOperationFailed|\InvalidArgumentException $exception) {
                $logger->warning('Das frühere Player-Wasserzeichen konnte nicht bereinigt werden.', [
                    'failure_type' => $exception::class,
                ]);
                $this->addFlash('warning', 'Das neue Wasserzeichen wurde gespeichert; die frühere private Datei konnte nicht bereinigt werden.');
            }
        }

        $this->addFlash('success', 'Das Player-Wasserzeichen wurde gespeichert.');

        return $this->privateResponse($this->redirectToRoute('admin_content_settings_index'));
    }

    #[Route('/remove', name: 'remove', methods: ['POST'])]
    public function remove(
        Request $request,
        SiteSettingsRepository $settingsRepository,
        VideoPlayerWatermarkUploadService $watermarks,
        EntityManagerInterface $entityManager,
        LoggerInterface $logger,
    ): Response {
        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid('admin_player_watermark', $token)) {
            return $this->privateResponse(new Response('', Response::HTTP_FORBIDDEN));
        }
        if ([] !== array_diff(array_keys($request->request->all()), ['_token'])
            || [] !== $request->files->all()
        ) {
            $this->addFlash('error', 'Die Wasserzeichen-Anfrage enthält unerwartete Daten.');

            return $this->privateResponse($this->redirectToRoute('admin_content_settings_index'));
        }

        $settings = $settingsRepository->findCurrent();
        if (!$settings instanceof SiteSettings || null === $settings->getVideoPlayerWatermarkObjectId()) {
            $this->addFlash('error', 'Es ist kein Wasserzeichenbild gespeichert.');

            return $this->privateResponse($this->redirectToRoute('admin_content_settings_index'));
        }

        $objectId = $settings->removeVideoPlayerWatermark();
        try {
            $entityManager->flush();
        } catch (\Throwable $exception) {
            $logger->error('Player-Wasserzeichen konnte nicht entfernt werden.', ['failure_type' => $exception::class]);
            $this->addFlash('error', 'Das Wasserzeichen konnte nicht entfernt werden.');

            return $this->privateResponse($this->redirectToRoute('admin_content_settings_index'));
        }

        if (null !== $objectId) {
            try {
                $watermarks->delete($objectId);
            } catch (PrivateStorageOperationFailed|\InvalidArgumentException $exception) {
                $logger->warning('Entferntes Player-Wasserzeichen konnte im privaten Speicher nicht bereinigt werden.', [
                    'failure_type' => $exception::class,
                ]);
                $this->addFlash('warning', 'Das Wasserzeichen wurde entfernt; die private Datei konnte nicht bereinigt werden.');
            }
        }

        $this->addFlash('success', 'Das Player-Wasserzeichen wurde entfernt.');

        return $this->privateResponse($this->redirectToRoute('admin_content_settings_index'));
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
