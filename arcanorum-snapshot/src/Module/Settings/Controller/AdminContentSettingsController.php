<?php

declare(strict_types=1);

namespace App\Module\Settings\Controller;

use App\Entity\CommentSortOrder;
use App\Entity\SiteSettings;
use App\Entity\VideoPlayerAspectRatio;
use App\Entity\VideoPlayerColorScheme;
use App\Entity\VideoPlayerEngine;
use App\Entity\VideoPlayerMaxWidth;
use App\Entity\VideoPreloadMode;
use App\Entity\VideoThumbnailResolution;
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
            'provider_thumbnail_resolution' => $current->getProviderThumbnailResolution()->value,
            'video_preload_mode' => $current->getVideoPreloadMode()->value,
            'video_player_aspect_ratio' => $current->getVideoPlayerAspectRatio()->value,
            'video_player_max_width' => $current->getVideoPlayerMaxWidth()->value,
            'video_player_embed_max_width' => $current->getVideoPlayerEmbedMaxWidth()->value,
            'video_player_home_max_width' => $current->getVideoPlayerHomeMaxWidth()->value,
            'player_featured_autoplay_enabled' => $current->isPlayerFeaturedAutoplayEnabled() ? '1' : '0',
            'auto_feature_view_threshold' => (string) $current->getAutoFeatureViewThreshold(),
            'video_player_color_scheme' => $current->getVideoPlayerColorScheme()->value,
            'video_player_watermark_enabled' => $current->isVideoPlayerWatermarkEnabled() ? '1' : '0',
            'public_video_embedding_enabled' => $current->isPublicVideoEmbeddingEnabled() ? '1' : '0',
            'player_autoplay_enabled' => $current->isPlayerAutoplayEnabled() ? '1' : '0',
            'player_autoplay_muted' => $current->isPlayerAutoplayMuted() ? '1' : '0',
            'default_comments_enabled' => $current->isDefaultCommentsEnabled() ? '1' : '0',
            'guest_comments_enabled' => $current->isGuestCommentsEnabled() ? '1' : '0',
            'comment_default_sort' => $current->getCommentDefaultSort()->value,
            'comment_rating_hide_threshold' => (string) $current->getCommentRatingHideThreshold(),
            'comments_per_page' => (string) $current->getCommentsPerPage(),
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
            'provider_thumbnail_resolution' => self::safeThumbnailResolution($input['provider_thumbnail_resolution'] ?? null, $fallback->getProviderThumbnailResolution()),
            'video_preload_mode' => self::safeVideoPreloadMode($input['video_preload_mode'] ?? null, $fallback->getVideoPreloadMode()),
            'video_player_aspect_ratio' => self::safeVideoPlayerAspectRatio($input['video_player_aspect_ratio'] ?? null, $fallback->getVideoPlayerAspectRatio()),
            'video_player_max_width' => self::safeVideoPlayerMaxWidth($input['video_player_max_width'] ?? null, $fallback->getVideoPlayerMaxWidth()),
            'video_player_embed_max_width' => self::safeVideoPlayerMaxWidth($input['video_player_embed_max_width'] ?? null, $fallback->getVideoPlayerEmbedMaxWidth()),
            'video_player_home_max_width' => self::safeVideoPlayerMaxWidth($input['video_player_home_max_width'] ?? null, $fallback->getVideoPlayerHomeMaxWidth()),
            'player_featured_autoplay_enabled' => self::safeBoolean($input['player_featured_autoplay_enabled'] ?? null, $fallback->isPlayerFeaturedAutoplayEnabled()),
            'auto_feature_view_threshold' => self::safeAutoFeatureViewThreshold($input['auto_feature_view_threshold'] ?? null, $fallback->getAutoFeatureViewThreshold()),
            'video_player_color_scheme' => self::safeVideoPlayerColorScheme($input['video_player_color_scheme'] ?? null, $fallback->getVideoPlayerColorScheme()),
            'video_player_watermark_enabled' => self::safeBoolean($input['video_player_watermark_enabled'] ?? null, $fallback->isVideoPlayerWatermarkEnabled()),
            'public_video_embedding_enabled' => self::safeBoolean($input['public_video_embedding_enabled'] ?? null, $fallback->isPublicVideoEmbeddingEnabled()),
            'player_autoplay_enabled' => self::safeBoolean($input['player_autoplay_enabled'] ?? null, $fallback->isPlayerAutoplayEnabled()),
            'player_autoplay_muted' => self::safeBoolean($input['player_autoplay_muted'] ?? null, $fallback->isPlayerAutoplayMuted()),
            'default_comments_enabled' => self::safeBoolean($input['default_comments_enabled'] ?? null, $fallback->isDefaultCommentsEnabled()),
            'guest_comments_enabled' => self::safeBoolean($input['guest_comments_enabled'] ?? null, $fallback->isGuestCommentsEnabled()),
            'comment_default_sort' => self::safeCommentSort($input['comment_default_sort'] ?? null, $fallback->getCommentDefaultSort()),
            'comment_rating_hide_threshold' => self::safeCommentRatingHideThreshold(
                $input['comment_rating_hide_threshold'] ?? null,
                $fallback->getCommentRatingHideThreshold(),
            ),
            'comments_per_page' => self::safeCommentsPerPage(
                $input['comments_per_page'] ?? null,
                $fallback->getCommentsPerPage(),
            ),
            'public_video_submissions_enabled' => self::safeBoolean($input['public_video_submissions_enabled'] ?? null, $fallback->isPublicVideoSubmissionsEnabled()),
        ];
    }

    private static function safeCommentSort(mixed $value, CommentSortOrder $fallback): string
    {
        return is_string($value) ? (CommentSortOrder::tryFrom($value) ?? $fallback)->value : $fallback->value;
    }

    private static function safeCommentRatingHideThreshold(mixed $value, int $fallback): string
    {
        if (is_int($value) && 0 <= $value && $value <= 1000) {
            return (string) $value;
        }
        if (is_string($value) && 1 === preg_match('/\A(?:0|[1-9][0-9]{0,3})\z/D', $value) && (int) $value <= 1000) {
            return $value;
        }

        return (string) $fallback;
    }

    private static function safeCommentsPerPage(mixed $value, int $fallback): string
    {
        if (is_int($value) && 1 <= $value && $value <= 100) {
            return (string) $value;
        }
        if (is_string($value) && 1 === preg_match('/\A(?:[1-9]|[1-9][0-9]|100)\z/D', $value)) {
            return $value;
        }

        return (string) $fallback;
    }

    private static function safeAutoFeatureViewThreshold(mixed $value, int $fallback): string
    {
        if (is_int($value) && 0 <= $value && $value <= SiteSettings::MAX_AUTO_FEATURE_VIEW_THRESHOLD) {
            return (string) $value;
        }
        if (is_string($value) && strlen($value) <= 12 && 1 === preg_match('/\A[+-]?[0-9]*\z/D', $value)) {
            return $value;
        }

        return (string) $fallback;
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

        return (VideoPlayerEngine::tryFrom($value) ?? $fallback)->value;
    }

    private static function safeThumbnailResolution(mixed $value, VideoThumbnailResolution $fallback): string
    {
        if (!is_string($value)) {
            return $fallback->value;
        }

        return (VideoThumbnailResolution::tryFrom($value) ?? $fallback)->value;
    }

    private static function safeVideoPreloadMode(mixed $value, VideoPreloadMode $fallback): string
    {
        if (!is_string($value)) {
            return $fallback->value;
        }

        return (VideoPreloadMode::tryFrom($value) ?? $fallback)->value;
    }

    private static function safeVideoPlayerAspectRatio(mixed $value, VideoPlayerAspectRatio $fallback): string
    {
        if (!is_string($value)) {
            return $fallback->value;
        }

        return (VideoPlayerAspectRatio::tryFrom($value) ?? $fallback)->value;
    }

    private static function safeVideoPlayerMaxWidth(mixed $value, VideoPlayerMaxWidth $fallback): string
    {
        if (!is_string($value)) {
            return $fallback->value;
        }

        return (VideoPlayerMaxWidth::tryFrom($value) ?? $fallback)->value;
    }

    private static function safeVideoPlayerColorScheme(mixed $value, VideoPlayerColorScheme $fallback): string
    {
        if (!is_string($value)) {
            return $fallback->value;
        }

        return (VideoPlayerColorScheme::tryFrom($value) ?? $fallback)->value;
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
