<?php

declare(strict_types=1);

namespace App\Module\Settings\Controller;

use App\Entity\AdminUser;
use App\Entity\SiteSettings;
use App\Module\Settings\Service\AdminEmailTestSender;
use App\Module\Settings\Service\EmailDeliveryConfiguration;
use App\Service\SiteSettingsManager;
use App\Service\SiteSettingsProvider;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/settings/email', name: 'admin_email_settings_')]
final class AdminEmailSettingsController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(SiteSettingsProvider $settings, EmailDeliveryConfiguration $delivery, LoggerInterface $logger): Response
    {
        try {
            $current = $settings->current();

            return $this->renderPage($current, [
                'from_address' => $current->getEmailFromAddress(),
            ], [], Response::HTTP_OK, $delivery->fromAddress(), $delivery->hasTransport());
        } catch (DbalException $exception) {
            $logger->error('E-Mail-Einstellungen konnten nicht sicher gelesen werden.', ['failure_type' => $exception::class]);

            return $this->renderPage(new SiteSettings(), ['from_address' => ''], [
                'form' => 'Die E-Mail-Einstellungen sind derzeit nicht verfügbar.',
            ], Response::HTTP_SERVICE_UNAVAILABLE, null, false);
        }
    }

    #[Route('', name: 'update', methods: ['POST'])]
    public function update(
        Request $request,
        SiteSettingsProvider $settings,
        SiteSettingsManager $manager,
        EmailDeliveryConfiguration $delivery,
        LoggerInterface $logger,
    ): Response {
        try {
            $current = $settings->current();
        } catch (DbalException $exception) {
            $logger->error('E-Mail-Einstellungen konnten nicht sicher gelesen werden.', ['failure_type' => $exception::class]);

            return $this->renderPage(new SiteSettings(), ['from_address' => ''], [
                'form' => 'Die E-Mail-Einstellungen sind derzeit nicht verfügbar.',
            ], Response::HTTP_SERVICE_UNAVAILABLE, null, false);
        }

        $form = ['from_address' => self::safeAddress($request->request->get('from_address'), $current->getEmailFromAddress())];
        $token = $request->request->get('token');
        if (!is_string($token) || !$this->isCsrfTokenValid('admin_email_settings', $token)) {
            return $this->privateResponse(new Response('', Response::HTTP_FORBIDDEN));
        }

        $input = $request->request->all();
        unset($input['token']);
        try {
            $errors = $manager->updateEmailSettings($input);
        } catch (DbalException $exception) {
            $logger->error('E-Mail-Einstellungen konnten nicht gespeichert werden.', ['failure_type' => $exception::class]);

            return $this->renderPage($current, $form, [
                'form' => 'Die E-Mail-Einstellungen konnten nicht gespeichert werden.',
            ], Response::HTTP_SERVICE_UNAVAILABLE, $delivery->fromAddress(), $delivery->hasTransport());
        }

        if ([] !== $errors) {
            return $this->renderPage($current, $form, $errors, Response::HTTP_UNPROCESSABLE_ENTITY, $delivery->fromAddress(), $delivery->hasTransport());
        }

        $this->addFlash('success', 'Die E-Mail-Einstellungen wurden gespeichert.');

        return $this->privateResponse($this->redirectToRoute('admin_email_settings_index'));
    }

    #[Route('/test', name: 'test', methods: ['POST'])]
    public function sendTest(
        Request $request,
        SiteSettingsProvider $settings,
        EmailDeliveryConfiguration $delivery,
        AdminEmailTestSender $sender,
        #[Autowire(service: 'limiter.admin_email_test')] RateLimiterFactory $limiter,
        LoggerInterface $logger,
    ): Response {
        $token = $request->request->get('token');
        if (!is_string($token) || !$this->isCsrfTokenValid('admin_email_test', $token)) {
            return $this->privateResponse(new Response('', Response::HTTP_FORBIDDEN));
        }

        $admin = $this->getUser();
        if (!$admin instanceof AdminUser) {
            throw $this->createAccessDeniedException();
        }

        try {
            $current = $settings->current();
            $fromAddress = $delivery->fromAddress();
            $transportConfigured = $delivery->hasTransport();
        } catch (\Throwable $exception) {
            $logger->error('Testmail wurde wegen einer nicht verfügbaren E-Mail-Konfiguration gesperrt.', ['failure_type' => $exception::class]);

            return $this->renderPage(new SiteSettings(), ['from_address' => ''], [
                'form' => 'Die E-Mail-Konfiguration ist derzeit nicht verfügbar.',
            ], Response::HTTP_SERVICE_UNAVAILABLE, null, false);
        }

        if ([] !== array_diff(array_keys($request->request->all()), ['token'])) {
            return $this->renderPage($current, ['from_address' => $current->getEmailFromAddress()], [
                'form' => 'Die Anfrage enthält unbekannte Testmail-Eingaben.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY, $fromAddress, $transportConfigured);
        }

        if (!$transportConfigured || null === $fromAddress) {
            return $this->renderPage($current, ['from_address' => $current->getEmailFromAddress()], [
                'form' => 'Der E-Mail-Versand ist noch nicht vollständig eingerichtet. Prüfen Sie die geschützte Serverkonfiguration.',
            ], Response::HTTP_SERVICE_UNAVAILABLE, $fromAddress, $transportConfigured);
        }

        $rateKey = hash('sha256', 'admin-email-test|'.$admin->getId().'|'.($request->getClientIp() ?? 'unknown'));
        try {
            if (!$limiter->create($rateKey)->consume()->isAccepted()) {
                return $this->renderPage($current, ['from_address' => $current->getEmailFromAddress()], [
                    'form' => 'Zu viele Testnachrichten. Bitte warten Sie, bevor Sie es erneut versuchen.',
                ], Response::HTTP_TOO_MANY_REQUESTS, $fromAddress, $transportConfigured);
            }
        } catch (\Throwable $exception) {
            $logger->warning('Testmail wurde wegen eines nicht verfügbaren Drosselungsdienstes gesperrt.', ['failure_type' => $exception::class]);

            return $this->renderPage($current, ['from_address' => $current->getEmailFromAddress()], [
                'form' => 'Der Versand ist derzeit nicht verfügbar. Bitte versuchen Sie es später erneut.',
            ], Response::HTTP_SERVICE_UNAVAILABLE, $fromAddress, $transportConfigured);
        }

        try {
            $sender->sendToAdministrator($admin);
        } catch (\Throwable $exception) {
            $logger->warning('Die administrative Testnachricht konnte nicht zugestellt werden.', ['failure_type' => $exception::class]);

            return $this->renderPage($current, ['from_address' => $current->getEmailFromAddress()], [
                'form' => 'Die Testnachricht konnte nicht zugestellt werden. Prüfen Sie die geschützte Serverkonfiguration.',
            ], Response::HTTP_SERVICE_UNAVAILABLE, $fromAddress, $transportConfigured);
        }

        $this->addFlash('success', 'Die Testnachricht wurde an Ihre Administratoradresse gesendet.');

        return $this->privateResponse($this->redirectToRoute('admin_email_settings_index'));
    }

    /** @param array<string, mixed> $form
     * @param array<string, string> $errors
     */
    private function renderPage(
        SiteSettings $settings,
        array $form,
        array $errors,
        int $status,
        ?string $fromAddress,
        bool $transportConfigured,
    ): Response {
        return $this->privateResponse($this->render('admin/settings/email.html.twig', [
            'settings' => $settings,
            'form' => $form,
            'errors' => $errors,
            'configured_from_address' => $fromAddress,
            'transport_configured' => $transportConfigured,
            'delivery_ready' => $transportConfigured && null !== $fromAddress,
        ], new Response(status: $status)));
    }

    private static function safeAddress(mixed $value, string $fallback): string
    {
        if (!is_string($value) || strlen($value) > 512 || 1 !== preg_match('//u', $value)) {
            return $fallback;
        }

        return mb_substr($value, 0, 254, 'UTF-8');
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
