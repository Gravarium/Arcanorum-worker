<?php

declare(strict_types=1);

namespace App\Module\Video\Controller;

use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Platform\Hosting\HostingCachePoolProvider;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use App\Platform\Security\RateLimitKeyFactory;
use App\Repository\VideoRepository;
use App\Service\SiteSettingsProvider;
use App\Service\VideoProviderImportException;
use App\Service\VideoProviderImportService;
use App\Service\VideoThumbnailImportBudget;
use App\Service\VideoThumbnailImportService;
use App\Service\VideoThumbnailUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
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

    #[Route('/{id}/thumbnail/provider-import', name: 'thumbnail_provider_import', requirements: ['id' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function importFromProvider(
        string $id,
        Request $request,
        VideoRepository $videos,
        VideoProviderImportService $providerImport,
        VideoThumbnailImportService $thumbnailImports,
        SiteSettingsProvider $siteSettings,
        HostingCachePoolProvider $importRateLimitCache,
        RateLimitKeyFactory $rateLimitKeys,
        EntityManagerInterface $entityManager,
        #[Autowire(service: 'limiter.video_provider_import')] RateLimiterFactory $importRateLimiter,
    ): Response {
        $video = $videos->find($id);
        if (!$video instanceof Video) {
            throw $this->createNotFoundException('Video wurde nicht gefunden.');
        }

        $token = self::input($request->request, '_token');
        if ('' === $token || !$this->isCsrfTokenValid('admin_video_thumbnail_provider_import_'.$video->getId(), $token)) {
            throw $this->createAccessDeniedException();
        }

        if ([] !== array_diff(array_keys($request->request->all()), ['_token', 'action'])
            || [] !== $request->files->all()
            || 'provider_import' !== self::input($request->request, 'action')
        ) {
            $this->addFlash('error', 'Die Provider-Vorschaubildanfrage enthält nicht erlaubte Felder.');

            return $this->redirectToRoute('admin_video_edit', ['id' => $video->getId()]);
        }

        if (!in_array($video->getSourceType(), [VideoSourceType::Embed, VideoSourceType::Url], true)) {
            $this->addFlash('error', 'Für diese Videoquelle ist kein sicheres Provider-Vorschaubild verfügbar.');

            return $this->redirectToRoute('admin_video_edit', ['id' => $video->getId()]);
        }

        if (!$importRateLimitCache->usesRedisPool()) {
            $this->addFlash('error', 'Der gemeinsame Importschutz ist nicht verfügbar. Das Provider-Vorschaubild wurde nicht abgerufen.');

            return $this->redirectToRoute('admin_video_edit', ['id' => $video->getId()]);
        }

        $admin = $this->getUser();
        if (null === $admin) {
            throw $this->createAccessDeniedException();
        }
        $limiterKey = $rateLimitKeys->forIdentity($admin->getUserIdentifier(), 'video-provider-import');
        $limit = $importRateLimiter->create($limiterKey)->consume();
        if (!$limit->isAccepted()) {
            $this->addFlash('error', 'Das Importlimit wurde erreicht. Bitte warte kurz und versuche es erneut.');
            $response = $this->redirectToRoute('admin_video_edit', ['id' => $video->getId()]);
            $response->headers->set('Retry-After', (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()));

            return $response;
        }

        try {
            $metadata = $providerImport->metadataForSourceUrl(
                $video->getSourceUrl(),
                $siteSettings->current()->getProviderThumbnailResolution(),
            );
        } catch (VideoProviderImportException) {
            $this->addFlash('error', 'Der Videoanbieter konnte kein sicheres Vorschaubild bereitstellen.');

            return $this->redirectToRoute('admin_video_edit', ['id' => $video->getId()]);
        }

        if (null === $metadata || !isset($metadata['thumbnail_url'])) {
            $this->addFlash('error', 'Für diese Videoquelle ist kein sicheres Provider-Vorschaubild verfügbar.');

            return $this->redirectToRoute('admin_video_edit', ['id' => $video->getId()]);
        }

        try {
            $thumbnail = $thumbnailImports->storeFromProviderUrl(
                $metadata['provider'],
                $metadata['thumbnail_url'],
                new VideoThumbnailImportBudget(),
            );
        } catch (\InvalidArgumentException|PrivateStorageOperationFailed) {
            $this->addFlash('error', 'Das Provider-Vorschaubild konnte nicht sicher übernommen werden.');

            return $this->redirectToRoute('admin_video_edit', ['id' => $video->getId()]);
        }

        $previousObjectId = $video->getThumbnailObjectId();
        try {
            $video->attachThumbnail($thumbnail['object_id'], $thumbnail['mime_type'], $thumbnail['size']);
            $entityManager->flush();
        } catch (\Throwable $exception) {
            try {
                $thumbnailImports->delete($thumbnail['object_id']);
            } catch (PrivateStorageOperationFailed|\InvalidArgumentException) {
                $this->addFlash('warning', 'Das neue Provider-Vorschaubild konnte nach dem Speicherfehler nicht bereinigt werden.');
            }

            throw $exception;
        }

        if (null !== $previousObjectId && $previousObjectId !== $thumbnail['object_id']) {
            try {
                $thumbnailImports->delete($previousObjectId);
            } catch (PrivateStorageOperationFailed|\InvalidArgumentException) {
                $this->addFlash('warning', 'Das frühere private Vorschaubild konnte nicht bereinigt werden.');
            }
        }

        $this->addFlash('success', 'Provider-Vorschaubild wurde sicher übernommen.');

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
