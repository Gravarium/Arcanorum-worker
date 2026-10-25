<?php

declare(strict_types=1);

namespace App\Module\Settings\Controller;

use App\Service\CookieConsentService;
use App\Service\SiteSettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/cookie-einstellungen', name: 'cookie_consent_')]
final class CookieConsentController extends AbstractController
{
    #[Route('', name: 'preferences', methods: ['GET'])]
    public function preferences(Request $request, CookieConsentService $consent, SiteSettingsProvider $settings): Response
    {
        return $this->privateResponse($this->render('settings/cookie_preferences.html.twig', [
            'settings' => $settings->current(),
            'choice' => $consent->currentChoice(),
            'token' => $consent->csrfToken($request),
        ]));
    }

    #[Route('', name: 'submit', methods: ['POST'])]
    public function submit(Request $request, CookieConsentService $consent): Response
    {
        if (!$consent->isCsrfTokenValid($request, $request->request->get('token'))) {
            return $this->privateResponse(new Response('', Response::HTTP_FORBIDDEN));
        }

        $choice = $request->request->get('choice');
        if (!is_string($choice) || !in_array($choice, ['all', 'necessary'], true)) {
            return $this->privateResponse(new Response('Ungültige Cookie-Auswahl.', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $response = $this->privateResponse($this->redirectToRoute('platform_home'));
        $response->headers->setCookie($consent->createConsentCookie($request, $choice));
        $response->headers->setCookie($consent->expireCsrfCookie($request));

        return $response;
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
