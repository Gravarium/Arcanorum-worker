<?php

declare(strict_types=1);

namespace App\Module\Settings\Controller;

use App\Entity\SiteSettings;
use App\Entity\VideoPopularitySort;
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
#[Route('/admin/settings/public-display', name: 'admin_public_display_settings_')]
final class AdminPublicDisplaySettingsController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(SiteSettingsProvider $settings): Response
    {
        return $this->renderSettings($settings->current(), self::settingsForm($settings->current()));
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
        $submittedToken = $input['token'] ?? null;
        unset($input['token']);

        if (!is_string($submittedToken) || !$this->isCsrfTokenValid('admin_public_display_settings', $submittedToken)) {
            return $this->privateResponse(new Response('', Response::HTTP_FORBIDDEN));
        }

        try {
            $errors = $manager->updatePublicDisplayOptions($input);
        } catch (DbalException $exception) {
            $logger->error('Öffentliche Anzeigeeinstellungen konnten nicht gespeichert werden.', [
                'failure_type' => $exception::class,
            ]);

            return $this->renderSettings($current, self::safeForm($input, $current), [
                'form' => 'Die Einstellungen konnten nicht gespeichert werden. Bitte versuchen Sie es erneut.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ([] !== $errors) {
            return $this->renderSettings($current, self::safeForm($input, $current), $errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('success', 'Die öffentlichen Anzeigeeinstellungen wurden gespeichert.');

        return $this->privateResponse($this->redirectToRoute('admin_public_display_settings_index'));
    }

    /** @return array<string, string> */
    private static function settingsForm(SiteSettings $settings): array
    {
        return [
            'homepage_featured_limit' => (string) $settings->getHomepageFeaturedLimit(),
            'homepage_video_limit' => (string) $settings->getHomepageVideoLimit(),
            'homepage_news_limit' => (string) $settings->getHomepageNewsLimit(),
            'public_video_page_limit' => (string) $settings->getPublicVideoPageLimit(),
            'popular_video_sort' => $settings->getPopularVideoSort()->value,
            'popular_video_period_days' => (string) $settings->getPopularVideoPeriodDays(),
            'currently_watching_limit' => (string) $settings->getCurrentlyWatchingLimit(),
            'public_statistics_enabled' => self::boolean($settings->isPublicStatisticsEnabled()),
            'tag_cloud_enabled' => self::boolean($settings->isTagCloudEnabled()),
            'tag_cloud_limit' => (string) $settings->getTagCloudLimit(),
            'tag_cloud_shuffle' => self::boolean($settings->isTagCloudShuffleEnabled()),
            'related_video_limit' => (string) $settings->getRelatedVideoLimit(),
            'top_rated_video_limit' => (string) $settings->getTopRatedVideoLimit(),
            'video_share_widget_enabled' => self::boolean($settings->isVideoShareWidgetEnabled()),
        ];
    }

    /** @param array<string, mixed> $input
     * @return array<string, string>
     */
    private static function safeForm(array $input, SiteSettings $fallback): array
    {
        $form = self::settingsForm($fallback);
        foreach ($form as $field => $fallbackValue) {
            $value = $input[$field] ?? null;
            if (is_string($value) && mb_strlen($value, 'UTF-8') <= 5 && 1 === preg_match('/\A[a-z0-9_]+\z/i', $value)) {
                $form[$field] = $value;
            }
        }

        $sort = $input['popular_video_sort'] ?? null;
        if (is_string($sort) && null !== VideoPopularitySort::tryFrom($sort)) {
            $form['popular_video_sort'] = $sort;
        }

        foreach (['public_statistics_enabled', 'tag_cloud_enabled', 'tag_cloud_shuffle', 'video_share_widget_enabled'] as $field) {
            $form[$field] = self::safeBoolean($input[$field] ?? null, '1' === $form[$field]);
        }

        return $form;
    }

    private static function safeBoolean(mixed $value, bool $fallback): string
    {
        if (true === $value || 1 === $value || '1' === $value) {
            return '1';
        }
        if (false === $value || 0 === $value || '0' === $value) {
            return '0';
        }

        return self::boolean($fallback);
    }

    private static function boolean(bool $value): string
    {
        return $value ? '1' : '0';
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
        return $this->privateResponse($this->render('admin/settings/public_display.html.twig', [
            'form' => $form,
            'errors' => $errors,
            'settings' => $settings,
        ], new Response(status: $status)));
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
