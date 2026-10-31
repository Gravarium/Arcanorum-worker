<?php

declare(strict_types=1);

namespace App\Module\Advertising\Http;

use App\Module\Advertising\Application\AdvertisingClickService;
use App\Module\Advertising\Application\AdvertisingDelivery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class AdvertisingDeliveryController extends AbstractController
{
    public function __construct(
        private readonly AdvertisingDelivery $delivery,
        private readonly AdvertisingClickService $clicks,
    ) {
    }

    #[Route('/advertising/placements/{placementId}', name: 'cms_advertising_delivery', methods: ['GET'])]
    public function deliver(string $placementId): Response
    {
        try {
            $html = $this->delivery->renderPlacement($placementId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        } catch (\DomainException) {
            throw new NotFoundHttpException('Advertising placement was not found.');
        }

        return new Response($html, Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
        ]);
    }

    #[Route('/advertising/click/{advertisementId}', name: 'cms_advertising_click', methods: ['GET'])]
    public function click(string $advertisementId, Request $request): RedirectResponse
    {
        $token = $request->query->get('token');
        if (!is_string($token)) {
            throw new NotFoundHttpException('Advertising destination was not found.');
        }

        $destination = $this->clicks->destinationForClick($advertisementId, $token, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        if (null === $destination) {
            throw new NotFoundHttpException('Advertising destination was not found.');
        }

        return new RedirectResponse($destination, Response::HTTP_FOUND, [
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
