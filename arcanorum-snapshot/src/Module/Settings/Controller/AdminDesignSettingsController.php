<?php

declare(strict_types=1);

namespace App\Module\Settings\Controller;

use App\Entity\SiteLayout;
use App\Entity\SiteSettings;
use App\Entity\SiteTheme;
use App\Service\SiteSettingsManager;
use App\Service\SiteSettingsProvider;
use App\Service\SiteThemeStylesheetFactory;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
        ) {
            return $this->privateResponse(new Response('', Response::HTTP_NOT_FOUND));
        }

        $appearance = [
            'theme' => $preview['appearance']['theme'],
            'layout' => $preview['appearance']['layout'],
            'primary_color' => $preview['appearance']['primary_color'],
            'accent_color' => $preview['appearance']['accent_color'],
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

    /** @return array{theme: string, layout: string, primary_color: string, accent_color: string} */
    private static function formData(SiteSettings $settings): array
    {
        return [
            'theme' => $settings->getPublicTheme()->value,
            'layout' => $settings->getPublicLayout()->value,
            'primary_color' => $settings->getPrimaryColor(),
            'accent_color' => $settings->getAccentColor(),
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{theme: string, layout: string, primary_color: string, accent_color: string}
     */
    private static function safeFormData(array $input, SiteSettings $fallback): array
    {
        $themeValue = $input['theme'] ?? null;
        $layoutValue = $input['layout'] ?? null;
        $primaryColor = $input['primary_color'] ?? null;
        $accentColor = $input['accent_color'] ?? null;

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
        ];
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
