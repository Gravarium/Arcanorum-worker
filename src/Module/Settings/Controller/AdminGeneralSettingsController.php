<?php

declare(strict_types=1);

namespace App\Module\Settings\Controller;

use App\Entity\SiteSettings;
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
#[Route('/admin/settings/general', name: 'admin_general_settings_')]
final class AdminGeneralSettingsController extends AbstractController
{
    private const array ALLOWED_INPUT_FIELDS = [
        'site_name',
        'tagline',
        'locale',
        'timezone',
        'seo_description',
        'search_indexing_enabled',
        'maintenance_mode_enabled',
        'maintenance_message',
        'cookie_consent_enabled',
        'cookie_consent_message',
        'member_registration_enabled',
        'social_google_enabled',
    ];

    private const array TIMEZONE_SUGGESTIONS = [
        'UTC',
        'Europe/Berlin',
        'Europe/London',
        'America/Los_Angeles',
        'America/New_York',
        'America/Sao_Paulo',
        'Asia/Dubai',
        'Asia/Kolkata',
        'Asia/Tokyo',
        'Australia/Sydney',
    ];

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(SiteSettingsProvider $settings): Response
    {
        $current = $settings->current();

        return $this->renderSettings($current, [
            'site_name' => $current->getSiteName(),
            'tagline' => $current->getTagline(),
            'locale' => $current->getDefaultLocale(),
            'timezone' => $current->getTimezone(),
            'seo_description' => $current->getSeoDescription(),
            'search_indexing_enabled' => $current->isSearchIndexingEnabled() ? '1' : '0',
            'maintenance_mode_enabled' => $current->isMaintenanceModeEnabled() ? '1' : '0',
            'maintenance_message' => $current->getMaintenanceMessage(),
            'cookie_consent_enabled' => $current->isCookieConsentEnabled() ? '1' : '0',
            'cookie_consent_message' => $current->getCookieConsentMessage(),
            'member_registration_enabled' => $current->isMemberRegistrationEnabled() ? '1' : '0',
            'social_google_enabled' => $current->isGoogleSocialLoginEnabled() ? '1' : '0',
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
        $formData = self::safeFormData($request->request->all(), $current);
        $submittedToken = $request->request->get('token');
        if (!is_string($submittedToken) || !$this->isCsrfTokenValid('admin_site_settings', $submittedToken)) {
            return $this->privateResponse(new Response('', Response::HTTP_FORBIDDEN));
        }

        $input = $request->request->all();
        unset($input['token']);
        if ([] !== array_diff(array_keys($input), self::ALLOWED_INPUT_FIELDS)) {
            return $this->renderSettings($current, $formData, [
                'form' => 'Die Anfrage enthält unbekannte Einstellungsfelder.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $errors = $manager->update($input);
        } catch (DbalException $exception) {
            $logger->error('Allgemeine Website-Einstellungen konnten nicht gespeichert werden.', [
                'failure_type' => $exception::class,
            ]);

            return $this->renderSettings($current, $formData, [
                'form' => 'Die Einstellungen konnten nicht gespeichert werden. Bitte versuchen Sie es erneut.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ([] !== $errors) {
            return $this->renderSettings($current, $formData, $errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('success', 'Die allgemeinen Website-Einstellungen wurden gespeichert.');

        return $this->privateResponse($this->redirectToRoute('admin_general_settings_index'));
    }

    /** @param array<string, mixed> $input
     * @param array<string, string> $errors
     */
    private function renderSettings(
        SiteSettings $settings,
        array $input,
        array $errors = [],
        int $status = Response::HTTP_OK,
    ): Response {
        return $this->privateResponse($this->render('admin/settings/general.html.twig', [
            'form' => $input,
            'errors' => $errors,
            'timezone_suggestions' => self::TIMEZONE_SUGGESTIONS,
            'supported_locales' => SiteSettings::SUPPORTED_LOCALES,
            'settings' => $settings,
        ], new Response(status: $status)));
    }

    /** @param array<string, mixed> $input
     * @return array<string, string>
     */
    private static function safeFormData(array $input, SiteSettings $fallback): array
    {
        return [
            'site_name' => self::safeString($input['site_name'] ?? null, $fallback->getSiteName(), 80),
            'tagline' => self::safeString($input['tagline'] ?? null, $fallback->getTagline(), 160),
            'locale' => self::safeString($input['locale'] ?? null, $fallback->getDefaultLocale(), 8),
            'timezone' => self::safeString($input['timezone'] ?? null, $fallback->getTimezone(), 64),
            'seo_description' => self::safeString($input['seo_description'] ?? null, $fallback->getSeoDescription(), 320),
            'search_indexing_enabled' => self::safeBoolean($input['search_indexing_enabled'] ?? null, $fallback->isSearchIndexingEnabled()),
            'maintenance_mode_enabled' => self::safeBoolean($input['maintenance_mode_enabled'] ?? null, $fallback->isMaintenanceModeEnabled()),
            'maintenance_message' => self::safeString($input['maintenance_message'] ?? null, $fallback->getMaintenanceMessage(), 320),
            'cookie_consent_enabled' => self::safeBoolean($input['cookie_consent_enabled'] ?? null, $fallback->isCookieConsentEnabled()),
            'cookie_consent_message' => self::safeString($input['cookie_consent_message'] ?? null, $fallback->getCookieConsentMessage(), 320),
            'member_registration_enabled' => self::safeBoolean($input['member_registration_enabled'] ?? null, $fallback->isMemberRegistrationEnabled()),
            'social_google_enabled' => self::safeBoolean($input['social_google_enabled'] ?? null, $fallback->isGoogleSocialLoginEnabled()),
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

    private static function safeString(mixed $value, string $fallback, int $maxLength): string
    {
        if (!is_string($value) || 1 !== preg_match('//u', $value)) {
            return $fallback;
        }

        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
