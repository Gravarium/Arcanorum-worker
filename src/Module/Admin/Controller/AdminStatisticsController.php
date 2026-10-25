<?php

declare(strict_types=1);

namespace App\Module\Admin\Controller;

use App\Repository\ContentStatisticsRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class AdminStatisticsController extends AbstractController
{
    #[Route('/admin/statistics', name: 'admin_statistics_index', methods: ['GET'])]
    public function index(Request $request, ContentStatisticsRepository $statistics): Response
    {
        $rawDays = $request->query->all()['days'] ?? '30';
        if (!in_array($rawDays, ['7', '30', '90'], true)) {
            return $this->noStore(new Response('', Response::HTTP_BAD_REQUEST));
        }

        $days = (int) $rawDays;
        $response = $this->render('admin/statistics/index.html.twig', [
            'period' => $days,
            'report' => $statistics->createdPerDay($days),
        ]);

        return $this->noStore($response);
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
