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
use App\Service\SiteSettingsProvider;
use App\Service\VideoMediaUploadService;
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
        ];
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('public_video_submission', $request->request->getString('_token'))) {
                return new Response(status: Response::HTTP_FORBIDDEN);
            }

            $values = [
                'title' => trim($request->request->getString('title')),
                'description' => trim($request->request->getString('description')),
                'category' => trim($request->request->getString('category')),
            ];
            if ('' !== trim($request->request->getString('website'))) {
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

            $uploadedField = $request->files->get('video_file');
            $upload = $uploadedField instanceof UploadedFile ? $uploadedField : null;
            if (null === $upload) {
                $errors[] = 'Für eine Einreichung ist eine Videodatei erforderlich.';
            }

            if ([] !== $errors) {
                foreach ($errors as $error) {
                    $this->addFlash('public_video_submission_error', $error);
                }

                return $this->renderSubmissionForm($categories, $values, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            if (!$upload instanceof UploadedFile) {
                throw new \LogicException('Eine gültige Videodatei wurde nach der Eingabeprüfung erwartet.');
            }

            try {
                $uploadedMedia = $mediaUploads->store($upload);
            } catch (\InvalidArgumentException $exception) {
                $this->addFlash('public_video_submission_error', $exception->getMessage());

                return $this->renderSubmissionForm($categories, $values, Response::HTTP_UNPROCESSABLE_ENTITY);
            } catch (PrivateStorageOperationFailed) {
                $this->addFlash('public_video_submission_error', 'Der private Videospeicher ist derzeit nicht verfügbar; die Einreichung wurde nicht angenommen.');

                return $this->renderSubmissionForm($categories, $values, Response::HTTP_SERVICE_UNAVAILABLE);
            }

            $video = new Video(
                $values['title'],
                $values['description'],
                '' === $values['category'] ? null : $values['category'],
                VideoSourceType::File,
                '',
                PublishingStatus::Draft,
                false,
                false,
            );
            $video->attachUploadedMedia(
                $uploadedMedia['object_id'],
                $uploadedMedia['mime_type'],
                $uploadedMedia['size'],
            );
            $user = $this->getUser();
            $submission = new VideoSubmission($video, $user instanceof MemberAccount ? $user : null);

            try {
                $entityManager->persist($video);
                $entityManager->persist($submission);
                $entityManager->flush();
            } catch (\Throwable $exception) {
                try {
                    $mediaUploads->delete($uploadedMedia['object_id']);
                } catch (PrivateStorageOperationFailed|\InvalidArgumentException) {
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
     * @param array{title: string, description: string, category: string} $values
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
}
