<?php

declare(strict_types=1);

namespace App\Module\Platform\Controller;

use App\Repository\NewsArticleRepository;
use App\Repository\VideoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'platform_home', methods: ['GET'])]
    public function index(NewsArticleRepository $articles, VideoRepository $videos): Response
    {
        $response = $this->render('home/index.html.twig', [
            'articles' => $articles->findPublished(limit: 6),
            'videos' => $videos->findPublished(limit: 6),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
