<?php

declare(strict_types=1);

namespace App\Module\Platform\Controller;

use App\Service\SiteSettingsProvider;
use App\Service\SiteThemeStylesheetFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class SiteThemeStylesheetController
{
    #[Route('/site-theme.css', name: 'site_theme_css', methods: ['GET'])]
    public function __invoke(
        Request $request,
        SiteSettingsProvider $settings,
        SiteThemeStylesheetFactory $stylesheets,
    ): Response {
        $content = $stylesheets->createPublicStylesheet($settings->current());
        $response = new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=0, must-revalidate',
        ]);
        $response->setEtag(hash('sha256', $content));

        $response->isNotModified($request);

        return $response;
    }
}
