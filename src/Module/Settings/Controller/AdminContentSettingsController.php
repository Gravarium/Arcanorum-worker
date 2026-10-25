<?php

declare(strict_types=1);

namespace App\Module\Settings\Controller;

use App\Entity\SiteSettings;
use App\Entity\VideoPlayerEngine;
use App\Entity\VideoPreloadMode;
use App\Service\SiteSettingsManager;
use App\Service\SiteSettingsProvider;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/settings/content', name: 'admin_content_settings_')]
final class AdminContentSettingsController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(SiteSettingsProvider $settings): Response
    {
        $current = $settings->current();

        return $this->renderSettings($current, [
            'video_player_engine' => $current->getVideoPlayerEngine()->value,
            'video_preload_mode' => $current->getVideoPreloadMode()->value,
            'player_autoplay_enabled' => $current->isPlayerAutoplayEnabled() ? '1' : '0',
            'player_autoplay_muted' => $current->isPlayerAutoplayMuted() ? '1' : '0',
            'default_comments_enabled' => $current->isDefaultCommentsEnabled() ? '1' : '0',
            'public_video_submissions_enabled' => $current->isPublicVideoSubmissionsEnabled() ? '1' : '0',
        ]);
    }

    #[Route('', name: 'update', methods: ['POST'])]
    public function update(
        Request $request,
        SiteSettingsProvider $settings,
        SiteSettingsManager $manager,
        LoggerInterface $logger,
    ): Response {
        $current = $settings->current();
        $input = $request->request->all();
        unset($input['token']);

        $submittedToken = $request->request->get('token');
        if (!is_string($submittedToken) || !$this->isCsrfTokenValid('admin_content_settings', $submittedToken)) {
            return $this->privateResponse(new Response('', Response::HTTP_FORBIDDEN));
        }

        try {
            $errors = $manager->updateContentOptions($input);
        } catch (DbalException $exception) {
            $logger->error('Inhaltsoptionen konnten nicht gespeichert werden.', [
                'failure_type' => $exception::class,
            ]);

            return $this->renderSettings($current, self::safeFormData($input, $current), [
                'form' => 'Die Einstellungen konnten nicht gespeichert werden. Bitte versuchen Sie es erneut.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ([] !== $errors) {
            return $this->renderSettings($current, self::safeFormData($input, $current), $errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('success', 'Die Inhaltsoptionen wurden gespeichert. Bestehende Inhalte und wartende Videoeinreichungen bleiben erhalten.');

        return $this->privateResponse($this->redirectToRoute('admin_content_settings_index'));
    }

    /** @param array<string, mixed> $input
     * @return array<string, string>
     */
    private static function safeFormData(array $input, SiteSettings $fallback): array
    {
        return [
            'video_player_engine' => self::safeVideoPlayerEngine($input['video_player_engine'] ?? null, $fallback->getVideoPlayerEngine()),
            'video_preload_mode' => self::safeVideoPreloadMode($input['video_preload_mode'] ?? null, $fallback->getVideoPreloadMode()),
            'player_autoplay_enabled' => self::safeBoolean($input['player_autoplay_enabled'] ?? null, $fallback->isPlayerAutoplayEnabled()),
            'player_autoplay_muted' => self::safeBoolean($input['player_autoplay_muted'] ?? null, $fallback->isPlayerAutoplayMuted()),
            'default_comments_enabled' => self::safeBoolean($input['default_comments_enabled'] ?? null, $fallback->isDefaultCommentsEnabled()),
            'public_video_submissions_enabled' => self::safeBoolean($input['public_video_submissions_enabled'] ?? null, $fallback->isPublicVideoSubmissionsEnabled()),
        ];
    }

    private static function safeBoolean(mixed $value, bool $fallback): string
    {
        if (true === $value || 1 === $value || '1' === $value) {
            return '1';
        }
        if (false === $value || 0 === $value || '0' === $value) {
            return '0';
        }

        return $fallback ? '1' : '0';
    }

    private static function safeVideoPlayerEngine(mixed $value, VideoPlayerEngine $fallback): string
    {
        if (!is_string($value)) {
            return $fallback->value;
        }

        return VideoPlayerEngine::tryFrom($value)?->value ?? $fallback->value;
    }

    private static function safeVideoPreloadMode(mixed $value, VideoPreloadMode $fallback): string
    {
        if (!is_string($value)) {
            return $fallback->value;
        }

        return VideoPreloadMode::tryFrom($value)?->value ?? $fallback->value;
    }

    /** @param array<string, string> $form
     * @param array<string, string> $errors
     */
    private function renderSettings(
        SiteSettings $settings,
        array $form,
        array $errors = [],
        int $status = Response::HTTP_OK,
    ): Response {
        return $this->privateResponse($this->render('admin/settings/content.html.twig', [
            'settings' => $settings,
            'form' => $form,
            'errors' => $errors,
        ], new Response(status: $status)));
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
