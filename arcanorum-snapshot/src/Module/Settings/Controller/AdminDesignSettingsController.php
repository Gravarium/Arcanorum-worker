<?php

declare(strict_types=1);

namespace App\Module\Settings\Controller;

use App\Entity\SiteLayout;
use App\Entity\SiteSettings;
use App\Entity\SiteTheme;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use App\Service\SiteLogoUploadService;
use App\Service\SiteSettingsManager;
use App\Service\SiteSettingsProvider;
use App\Service\SiteThemeStylesheetFactory;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/settings/design', name: 'admin_design_settings_')]
final class AdminDesignSettingsController extends AbstractController
{
    private const string PREVIEW_SESSION_KEY = '_arcanorum_design_preview';
    private const int PREVIEW_TTL_SECONDS = 300;

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(SiteSettingsProvider $settings): Response
    {
        $current = $settings->current();

        return $this->renderSettings($current, self::formData($current));
    }

    #[Route('', name: 'update', methods: ['POST'])]
    public function update(
        Request $request,
        SiteSettingsProvider $settings,
        SiteSettingsManager $manager,
        LoggerInterface $logger,
    ): Response {
        $current = $settings->current();
        $submittedToken = $request->request->get('_token');
        if (!is_string($submittedToken) || !$this->isCsrfTokenValid('admin_design_settings', $submittedToken)) {
            return $this->privateResponse(new Response('', Response::HTTP_FORBIDDEN));
        }

        $action = $request->request->get('action');
        if (!is_string($action) || !in_array($action, ['preview', 'save'], true)) {
            return $this->renderSettings($current, self::formData($current), [
                'form' => 'Bitte wählen Sie eine gültige Aktion.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $input = $request->request->all();
        unset($input['_token'], $input['action']);
        $form = self::safeFormData($input, $current);
        $candidate = clone $current;
        $errors = $candidate->updateAppearance($input);
        if ([] !== $errors) {
            return $this->renderSettings($current, $form, $errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ('preview' === $action) {
            $previewToken = bin2hex(random_bytes(32));
            $request->getSession()->set(self::PREVIEW_SESSION_KEY, [
                'token' => $previewToken,
                'expires_at' => time() + self::PREVIEW_TTL_SECONDS,
                'appearance' => [
                    'theme' => $candidate->getPublicTheme()->value,
                    'layout' => $candidate->getPublicLayout()->value,
                    'primary_color' => $candidate->getPrimaryColor(),
                    'accent_color' => $candidate->getAccentColor(),
                    'heading_color' => $candidate->getHeadingColor(),
                    'content_background_color' => $candidate->getContentBackgroundColor(),
                    'content_text_color' => $candidate->getContentTextColor(),
                    'body_background_color' => $candidate->getBodyBackgroundColor(),
                    'header_background_color' => $candidate->getHeaderBackgroundColor(),
                    'header_pane_color' => $candidate->getHeaderPaneColor(),
                    'navigation_background_color' => $candidate->getNavigationBackgroundColor(),
                    'navigation_link_color' => $candidate->getNavigationLinkColor(),
                    'content_link_color' => $candidate->getContentLinkColor(),
                    'video_link_color' => $candidate->getVideoLinkColor(),
                    'site_title_color' => $candidate->getSiteTitleColor(),
                    'footer_background_color' => $candidate->getFooterBackgroundColor(),
                    'footer_link_color' => $candidate->getFooterLinkColor(),
                    'content_width' => $candidate->getContentWidth(),
                ],
            ]);

            return $this->renderSettings($current, $form, [], Response::HTTP_OK, $previewToken, $candidate);
        }

        try {
            $errors = $manager->updateAppearance($input);
        } catch (DbalException $exception) {
            $logger->error('Theme- und Layouteinstellungen konnten nicht gespeichert werden.', [
                'failure_type' => $exception::class,
            ]);

            return $this->renderSettings($current, $form, [
                'form' => 'Die Designoptionen konnten nicht gespeichert werden. Bitte versuchen Sie es erneut.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ([] !== $errors) {
            return $this->renderSettings($current, $form, $errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('success', 'Theme, Farben und Layout wurden gespeichert.');

        return $this->privateResponse($this->redirectToRoute('admin_design_settings_index'));
    }

    #[Route('/logo', name: 'logo_upload', methods: ['POST'])]
    public function uploadLogo(
        Request $request,
        SiteSettingsProvider $settings,
        SiteSettingsManager $manager,
        SiteLogoUploadService $logos,
        LoggerInterface $logger,
    ): Response {
        $current = $settings->current();
        if (!$this->validLogoRequest($request, true)) {
            return $this->privateResponse(new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $submittedToken = $request->request->get('_token');
        if (!is_string($submittedToken) || !$this->isCsrfTokenValid('admin_design_logo', $submittedToken)) {
            return $this->privateResponse(new Response('', Response::HTTP_FORBIDDEN));
        }

        $upload = $request->files->get('logo');
        if (!$upload instanceof UploadedFile) {
            return $this->renderSettings($current, self::formData($current), [
                'logo' => 'Bitte wählen Sie ein Logo aus.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $stored = $logos->store($upload);
        } catch (\InvalidArgumentException $exception) {
            return $this->renderSettings($current, self::formData($current), [
                'logo' => $exception->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (PrivateStorageOperationFailed $exception) {
            $logger->error('Das private Website-Logo konnte nicht gespeichert werden.', [
                'failure_type' => $exception::class,
            ]);

            return $this->renderSettings($current, self::formData($current), [
                'logo' => 'Das Logo konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            $manager->replaceSiteLogo($stored['object_id'], $stored['mime_type'], $stored['size'], $stored['sha256']);
        } catch (\Throwable $exception) {
            try {
                $logos->delete($stored['object_id']);
            } catch (\Throwable $cleanupFailure) {
                $logger->error('Ein nicht gespeichertes Website-Logo konnte nicht bereinigt werden.', [
                    'failure_type' => $cleanupFailure::class,
                ]);
            }
            $logger->error('Das Website-Logo konnte nicht dauerhaft zugeordnet werden.', [
                'failure_type' => $exception::class,
            ]);

            return $this->privateResponse(new Response(
                'Das Logo konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.',
                Response::HTTP_SERVICE_UNAVAILABLE,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            ));
        }

        $this->addFlash('success', 'Das Website-Logo wurde gespeichert.');

        return $this->privateResponse($this->redirectToRoute('admin_design_settings_index'));
    }

    #[Route('/logo/remove', name: 'logo_remove', methods: ['POST'])]
    public function removeLogo(
        Request $request,
        SiteSettingsProvider $settings,
        SiteSettingsManager $manager,
        LoggerInterface $logger,
    ): Response {
        $current = $settings->current();
        if (!$this->validLogoRequest($request, false)) {
            return $this->privateResponse(new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $submittedToken = $request->request->get('_token');
        if (!is_string($submittedToken) || !$this->isCsrfTokenValid('admin_design_logo', $submittedToken)) {
            return $this->privateResponse(new Response('', Response::HTTP_FORBIDDEN));
        }

        try {
            $manager->removeSiteLogo();
        } catch (\Throwable $exception) {
            $logger->error('Das Website-Logo konnte nicht entfernt werden.', [
                'failure_type' => $exception::class,
            ]);

            return $this->privateResponse(new Response(
                'Das Logo konnte nicht entfernt werden. Bitte versuchen Sie es erneut.',
                Response::HTTP_SERVICE_UNAVAILABLE,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            ));
        }

        $this->addFlash('success', 'Das Website-Logo wurde entfernt.');

        return $this->privateResponse($this->redirectToRoute('admin_design_settings_index'));
    }

    private function validLogoRequest(Request $request, bool $upload): bool
    {
        $parameters = $request->request->all();
        if (1 !== count($parameters) || !array_key_exists('_token', $parameters)) {
            return false;
        }

        $files = $request->files->all();
        if ($upload) {
            return 1 === count($files) && array_key_exists('logo', $files);
        }

        return [] === $files;
    }

    #[Route('/preview/{token}.css', name: 'preview_css', requirements: ['token' => '[a-f0-9]{64}'], methods: ['GET'])]
    public function previewStylesheet(
        string $token,
        Request $request,
        SiteThemeStylesheetFactory $stylesheets,
    ): Response {
        $preview = $request->getSession()->get(self::PREVIEW_SESSION_KEY);
        if (!is_array($preview)
            || !isset($preview['token'], $preview['expires_at'], $preview['appearance'])
            || !is_string($preview['token'])
            || !hash_equals($preview['token'], $token)
            || !is_int($preview['expires_at'])
            || $preview['expires_at'] < time()
            || !is_array($preview['appearance'])
            || !is_string($preview['appearance']['theme'] ?? null)
            || !is_string($preview['appearance']['layout'] ?? null)
            || !is_string($preview['appearance']['primary_color'] ?? null)
            || !is_string($preview['appearance']['accent_color'] ?? null)
            || !is_string($preview['appearance']['heading_color'] ?? null)
            || !is_string($preview['appearance']['content_background_color'] ?? null)
            || !is_string($preview['appearance']['content_text_color'] ?? null)
            || !is_string($preview['appearance']['body_background_color'] ?? null)
            || !is_string($preview['appearance']['header_background_color'] ?? null)
            || !is_string($preview['appearance']['header_pane_color'] ?? null)
            || !is_string($preview['appearance']['navigation_background_color'] ?? null)
            || !is_string($preview['appearance']['navigation_link_color'] ?? null)
            || !is_string($preview['appearance']['content_link_color'] ?? null)
            || !is_string($preview['appearance']['video_link_color'] ?? null)
            || !is_string($preview['appearance']['site_title_color'] ?? null)
            || !is_string($preview['appearance']['footer_background_color'] ?? null)
            || !is_string($preview['appearance']['footer_link_color'] ?? null)
            || !is_int($preview['appearance']['content_width'] ?? null)
        ) {
            return $this->privateResponse(new Response('', Response::HTTP_NOT_FOUND));
        }

        $appearance = [
            'theme' => $preview['appearance']['theme'],
            'layout' => $preview['appearance']['layout'],
            'primary_color' => $preview['appearance']['primary_color'],
            'accent_color' => $preview['appearance']['accent_color'],
            'heading_color' => $preview['appearance']['heading_color'],
            'content_background_color' => $preview['appearance']['content_background_color'],
            'content_text_color' => $preview['appearance']['content_text_color'],
            'body_background_color' => $preview['appearance']['body_background_color'],
            'header_background_color' => $preview['appearance']['header_background_color'],
            'header_pane_color' => $preview['appearance']['header_pane_color'],
            'navigation_background_color' => $preview['appearance']['navigation_background_color'],
            'navigation_link_color' => $preview['appearance']['navigation_link_color'],
            'content_link_color' => $preview['appearance']['content_link_color'],
            'video_link_color' => $preview['appearance']['video_link_color'],
            'site_title_color' => $preview['appearance']['site_title_color'],
            'footer_background_color' => $preview['appearance']['footer_background_color'],
            'footer_link_color' => $preview['appearance']['footer_link_color'],
            'content_width' => $preview['appearance']['content_width'],
        ];
        $candidate = new SiteSettings();
        if ([] !== $candidate->updateAppearance($appearance)) {
            return $this->privateResponse(new Response('', Response::HTTP_NOT_FOUND));
        }

        $response = new Response($stylesheets->createPreviewStylesheet($candidate), Response::HTTP_OK, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        return $this->privateResponse($response);
    }

    /** @param array<string, string> $form
     * @param array<string, string> $errors
     */
    private function renderSettings(
        SiteSettings $settings,
        array $form,
        array $errors = [],
        int $status = Response::HTTP_OK,
        ?string $previewToken = null,
        ?SiteSettings $previewSettings = null,
    ): Response {
        return $this->privateResponse($this->render('admin/settings/design.html.twig', [
            'errors' => $errors,
            'form' => $form,
            'layout_options' => SiteLayout::cases(),
            'preview_settings' => $previewSettings ?? $settings,
            'preview_token' => $previewToken,
            'settings' => $settings,
            'theme_options' => SiteTheme::cases(),
        ], new Response(status: $status)));
    }

    /** @return array<string, string> */
    private static function formData(SiteSettings $settings): array
    {
        return [
            'theme' => $settings->getPublicTheme()->value,
            'layout' => $settings->getPublicLayout()->value,
            'primary_color' => $settings->getPrimaryColor(),
            'accent_color' => $settings->getAccentColor(),
            'heading_color' => $settings->getHeadingColor(),
            'content_background_color' => $settings->getContentBackgroundColor(),
            'content_text_color' => $settings->getContentTextColor(),
            'body_background_color' => $settings->getBodyBackgroundColor(),
            'header_background_color' => $settings->getHeaderBackgroundColor(),
            'header_pane_color' => $settings->getHeaderPaneColor(),
            'navigation_background_color' => $settings->getNavigationBackgroundColor(),
            'navigation_link_color' => $settings->getNavigationLinkColor(),
            'content_link_color' => $settings->getContentLinkColor(),
            'video_link_color' => $settings->getVideoLinkColor(),
            'site_title_color' => $settings->getSiteTitleColor(),
            'footer_background_color' => $settings->getFooterBackgroundColor(),
            'footer_link_color' => $settings->getFooterLinkColor(),
            'content_width' => (string) $settings->getContentWidth(),
        ];
    }

    /** @param array<string, mixed> $input
     * @return array<string, string>
     */
    private static function safeFormData(array $input, SiteSettings $fallback): array
    {
        $themeValue = $input['theme'] ?? null;
        $layoutValue = $input['layout'] ?? null;
        $primaryColor = $input['primary_color'] ?? null;
        $accentColor = $input['accent_color'] ?? null;
        $headingColor = $input['heading_color'] ?? null;
        $contentBackgroundColor = $input['content_background_color'] ?? null;
        $contentTextColor = $input['content_text_color'] ?? null;
        $bodyBackgroundColor = $input['body_background_color'] ?? null;
        $headerBackgroundColor = $input['header_background_color'] ?? null;
        $headerPaneColor = $input['header_pane_color'] ?? null;
        $navigationBackgroundColor = $input['navigation_background_color'] ?? null;
        $navigationLinkColor = $input['navigation_link_color'] ?? null;
        $contentLinkColor = $input['content_link_color'] ?? null;
        $videoLinkColor = $input['video_link_color'] ?? null;
        $siteTitleColor = $input['site_title_color'] ?? null;
        $footerBackgroundColor = $input['footer_background_color'] ?? null;
        $footerLinkColor = $input['footer_link_color'] ?? null;
        $contentWidth = $input['content_width'] ?? null;

        return [
            'theme' => is_string($themeValue) && null !== ($theme = SiteTheme::tryFrom($themeValue))
                ? $theme->value
                : $fallback->getPublicTheme()->value,
            'layout' => is_string($layoutValue) && null !== ($layout = SiteLayout::tryFrom($layoutValue))
                ? $layout->value
                : $fallback->getPublicLayout()->value,
            'primary_color' => is_string($primaryColor) && 1 === preg_match('/\A#[a-fA-F0-9]{6}\z/', $primaryColor)
                ? strtoupper($primaryColor)
                : $fallback->getPrimaryColor(),
            'accent_color' => is_string($accentColor) && 1 === preg_match('/\A#[a-fA-F0-9]{6}\z/', $accentColor)
                ? strtoupper($accentColor)
                : $fallback->getAccentColor(),
            'heading_color' => self::safeHexFormValue($headingColor, $fallback->getHeadingColor()),
            'content_background_color' => self::safeHexFormValue($contentBackgroundColor, $fallback->getContentBackgroundColor()),
            'content_text_color' => self::safeHexFormValue($contentTextColor, $fallback->getContentTextColor()),
            'body_background_color' => self::safeHexFormValue($bodyBackgroundColor, $fallback->getBodyBackgroundColor()),
            'header_background_color' => self::safeHexFormValue($headerBackgroundColor, $fallback->getHeaderBackgroundColor()),
            'header_pane_color' => self::safeHexFormValue($headerPaneColor, $fallback->getHeaderPaneColor()),
            'navigation_background_color' => self::safeHexFormValue($navigationBackgroundColor, $fallback->getNavigationBackgroundColor()),
            'navigation_link_color' => self::safeHexFormValue($navigationLinkColor, $fallback->getNavigationLinkColor()),
            'content_link_color' => self::safeHexFormValue($contentLinkColor, $fallback->getContentLinkColor()),
            'video_link_color' => self::safeHexFormValue($videoLinkColor, $fallback->getVideoLinkColor()),
            'site_title_color' => self::safeHexFormValue($siteTitleColor, $fallback->getSiteTitleColor()),
            'footer_background_color' => self::safeHexFormValue($footerBackgroundColor, $fallback->getFooterBackgroundColor()),
            'footer_link_color' => self::safeHexFormValue($footerLinkColor, $fallback->getFooterLinkColor()),
            'content_width' => is_string($contentWidth) && 1 === preg_match('/\A[0-9]{3,4}\z/', $contentWidth)
                && (int) $contentWidth >= 640 && (int) $contentWidth <= 1920
                    ? $contentWidth
                    : (string) $fallback->getContentWidth(),
        ];
    }

    private static function safeHexFormValue(mixed $value, string $fallback): string
    {
        return is_string($value) && 1 === preg_match('/\A#[a-fA-F0-9]{6}\z/', $value)
            ? strtoupper($value)
            : $fallback;
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
