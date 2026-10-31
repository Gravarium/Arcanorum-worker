<?php

declare(strict_types=1);

namespace App\Module\Video\Controller;

use App\Entity\ContentCategoryType;
use App\Entity\MemberAccount;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Entity\VideoSubmission;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use App\Repository\ContentCategoryRepository;
use App\Repository\VideoRepository;
use App\Service\ExternalVideoEmbedResolver;
use App\Service\SiteSettingsProvider;
use App\Service\VideoMediaUploadService;
use App\Service\VideoSourceUrlLock;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/video-submissions', name: 'public_video_submission_')]
final class PublicVideoSubmissionController extends AbstractController
{
    private const int MAX_DESCRIPTION_LENGTH = 20000;
    private const int COOLDOWN_SECONDS = 60;

    #[Route('', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        ContentCategoryRepository $categories,
        VideoRepository $videos,
        ExternalVideoEmbedResolver $embedResolver,
        VideoSourceUrlLock $sourceLock,
        VideoMediaUploadService $mediaUploads,
        EntityManagerInterface $entityManager,
        SiteSettingsProvider $settings,
    ): Response {
        if (!$settings->current()->isPublicVideoSubmissionsEnabled()) {
            $response = new Response(status: Response::HTTP_NOT_FOUND);
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

            return $response;
        }

        $values = [
            'title' => '',
            'description' => '',
            'category' => '',
            'source_url' => '',
        ];
        if ($request->isMethod('POST')) {
            $submitted = $request->request->all();
            $token = $submitted['_token'] ?? null;
            if (!is_string($token) || '' === $token || !$this->isCsrfTokenValid('public_video_submission', $token)) {
                return new Response(status: Response::HTTP_FORBIDDEN);
            }

            $allowedFields = ['_token', 'title', 'description', 'category', 'source_url', 'website'];
            $hasInvalidFields = [] !== array_diff(array_keys($submitted), $allowedFields);
            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $submitted) && !is_string($submitted[$field])) {
                    $hasInvalidFields = true;
                }
            }

            $values = [
                'title' => self::stringInput($submitted, 'title'),
                'description' => self::stringInput($submitted, 'description'),
                'category' => self::stringInput($submitted, 'category'),
                'source_url' => self::stringInput($submitted, 'source_url'),
            ];
            if ($hasInvalidFields) {
                $this->addFlash('public_video_submission_error', 'Die Anfrage enthält ungültige oder unbekannte Eingabefelder.');

                return $this->renderSubmissionForm($categories, $values, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            if ('' !== self::stringInput($submitted, 'website')) {
                return $this->redirectToRoute('public_video_submission_new');
            }

            $session = $request->getSession();
            $lastSubmission = $session->get('public_video_submission_last_at');
            if (is_int($lastSubmission) && time() - $lastSubmission < self::COOLDOWN_SECONDS) {
                $this->addFlash('public_video_submission_error', 'Bitte warte kurz, bevor du ein weiteres Video einreichst.');

                return $this->renderSubmissionForm($categories, $values, Response::HTTP_TOO_MANY_REQUESTS);
            }

            $errors = [];
            if (1 !== preg_match('//u', $values['title'])
                || 1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $values['title'])
                || '' === $values['title']
                || mb_strlen($values['title'], 'UTF-8') > 180
            ) {
                $errors[] = 'Der Titel muss 1 bis 180 Zeichen lang sein und gültiges UTF-8 enthalten.';
            }
            if (1 !== preg_match('//u', $values['description'])
                || 1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $values['description'])
                || mb_strlen($values['description'], 'UTF-8') > self::MAX_DESCRIPTION_LENGTH
            ) {
                $errors[] = 'Die Beschreibung darf höchstens 20.000 Zeichen lang sein und keine unzulässigen Steuerzeichen enthalten.';
            }
            if (mb_strlen($values['category'], 'UTF-8') > 100
                || ('' !== $values['category']
                    && null === $categories->findForTypeAndName(ContentCategoryType::Video, $values['category']))
            ) {
                $errors[] = 'Bitte wähle eine vorhandene Videokategorie.';
            }

            if (mb_strlen($values['source_url'], 'UTF-8') > 2048
                || ('' !== $values['source_url'] && 1 !== preg_match('//u', $values['source_url']))
                || ('' !== $values['source_url'] && 1 === preg_match('/[\x00-\x20\x7f]/', $values['source_url']))
            ) {
                $errors[] = 'Bitte gib eine gültige Videoquelle von YouTube, Vimeo, Dailymotion, Facebook, Google Drive, Rutube, VK oder Youku ein.';
            }

            $files = $request->files->all();
            if ([] !== array_diff(array_keys($files), ['video_file'])) {
                $errors[] = 'Die Anfrage enthält ein nicht unterstütztes Uploadfeld.';
            }
            $uploadedField = $files['video_file'] ?? null;
            if (null !== $uploadedField && !$uploadedField instanceof UploadedFile) {
                $errors[] = 'Der Video-Upload enthält ein ungültiges Dateifeld.';
            }
            $upload = $uploadedField instanceof UploadedFile ? $uploadedField : null;
            if (null === $upload && '' === $values['source_url']) {
                $errors[] = 'Wähle eine Video- oder MP3-Datei oder gib eine gültige unterstützte Provider-URL ein.';
            } elseif (null !== $upload && '' !== $values['source_url']) {
                $errors[] = 'Reiche entweder eine Video-/MP3-Datei oder eine externe Video-URL ein, nicht beides.';
            }

            $canonicalSourceUrl = '' === $values['source_url'] ? null : $embedResolver->resolve($values['source_url']);
            if ('' !== $values['source_url'] && null === $canonicalSourceUrl) {
                $errors[] = 'Es werden nur sichere, einbettbare Videos von unterstützten Providern angenommen.';
            }

            if ([] !== $errors) {
                foreach ($errors as $error) {
                    $this->addFlash('public_video_submission_error', $error);
                }

                return $this->renderSubmissionForm($categories, $values, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $uploadedMedia = null;
            if (null !== $upload) {
                try {
                    $uploadedMedia = $mediaUploads->store($upload);
                } catch (\InvalidArgumentException $exception) {
                    $this->addFlash('public_video_submission_error', $exception->getMessage());

                    return $this->renderSubmissionForm($categories, $values, Response::HTTP_UNPROCESSABLE_ENTITY);
                } catch (PrivateStorageOperationFailed) {
                    $this->addFlash('public_video_submission_error', 'Der private Videospeicher ist derzeit nicht verfügbar; die Einreichung wurde nicht angenommen.');

                    return $this->renderSubmissionForm($categories, $values, Response::HTTP_SERVICE_UNAVAILABLE);
                }
            }

            $video = new Video(
                $values['title'],
                $values['description'],
                '' === $values['category'] ? null : $values['category'],
                null !== $uploadedMedia ? VideoSourceType::File : VideoSourceType::Embed,
                $canonicalSourceUrl ?? '',
                PublishingStatus::Draft,
                false,
                false,
            );
            if (null !== $uploadedMedia) {
                $video->attachUploadedMedia(
                    $uploadedMedia['object_id'],
                    $uploadedMedia['mime_type'],
                    $uploadedMedia['size'],
                );
            }
            $user = $this->getUser();
            $submission = new VideoSubmission($video, $user instanceof MemberAccount ? $user : null);

            try {
                $created = $entityManager->getConnection()->transactional(function (Connection $connection) use (
                    $canonicalSourceUrl,
                    $sourceLock,
                    $videos,
                    $entityManager,
                    $video,
                    $submission,
                ): bool {
                    if (null !== $canonicalSourceUrl) {
                        $sourceLock->lock($connection, [$canonicalSourceUrl]);
                        if (null !== $videos->findBySourceUrl($canonicalSourceUrl)) {
                            return false;
                        }
                    }

                    $entityManager->persist($video);
                    $entityManager->persist($submission);
                    $entityManager->flush();

                    return true;
                });
                if (!$created) {
                    if (null !== $uploadedMedia) {
                        $mediaUploads->delete($uploadedMedia['object_id']);
                    }
                    $this->addFlash('public_video_submission_error', 'Dieses Video wurde bereits eingereicht.');

                    return $this->renderSubmissionForm($categories, $values, Response::HTTP_CONFLICT);
                }
            } catch (\Throwable $exception) {
                if (null !== $uploadedMedia) {
                    try {
                        $mediaUploads->delete($uploadedMedia['object_id']);
                    } catch (PrivateStorageOperationFailed|\InvalidArgumentException) {
                    }
                }

                throw $exception;
            }

            $session->set('public_video_submission_last_at', time());
            $this->addFlash('public_video_submission_success', 'Danke. Deine Videoeinreichung wurde zur Prüfung vorgemerkt.');

            return $this->redirectToRoute('public_video_submission_new');
        }

        return $this->renderSubmissionForm($categories, $values);
    }

    /**
     * @param array{title: string, description: string, category: string, source_url: string} $values
     */
    private function renderSubmissionForm(
        ContentCategoryRepository $categories,
        array $values,
        int $status = Response::HTTP_OK,
    ): Response {
        return $this->render('videos/submit.html.twig', [
            'categories' => $categories->findForAdmin(ContentCategoryType::Video),
            'values' => $values,
        ], new Response(status: $status));
    }

    /** @param array<string, mixed> $payload */
    private static function stringInput(array $payload, string $key): string
    {
        $value = $payload[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }
}
