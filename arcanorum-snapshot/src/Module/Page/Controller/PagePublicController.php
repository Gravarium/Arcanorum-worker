<?php

declare(strict_types=1);

namespace App\Module\Page\Controller;

use App\Repository\StaticPageRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/pages', name: 'page_')]
final class PagePublicController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(StaticPageRepository $pages): Response
    {
        return $this->render('pages/index.html.twig', [
            'pages' => $pages->findPublished(),
        ]);
    }

    #[Route(
        '/{slug}',
        name: 'show',
        requirements: ['slug' => '[^/]+'],
        methods: ['GET'],
    )]
    public function show(string $slug, StaticPageRepository $pages): Response
    {
        $page = $pages->findPublishedBySlug($slug);
        if (null === $page) {
            return $this->render('public/not_found.html.twig', [
                'content_type' => 'Seite',
            ], new Response(status: Response::HTTP_NOT_FOUND));
        }

        return $this->render('pages/show.html.twig', ['page' => $page]);
    }
}
