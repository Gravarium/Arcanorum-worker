<?php

declare(strict_types=1);

namespace App\Module\Admin\Controller;

use App\Module\Settings\Service\ModuleAvailability;
use App\Repository\SearchTermStatisticsRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/search-statistics', name: 'admin_search_statistics_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminSearchStatisticsController extends AbstractController
{
    private const int PAGE_SIZE = 50;

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, SearchTermStatisticsRepository $statistics, ModuleAvailability $modules): Response
    {
        if (!$modules->isEnabled('search')) {
            return $this->noStore(new Response('', Response::HTTP_NOT_FOUND));
        }

        $rawSearch = $request->query->all()['q'] ?? '';
        if (!is_string($rawSearch) || strlen($rawSearch) > 400 || 1 !== preg_match('//u', $rawSearch) || 1 === preg_match('/[\x00-\x1F\x7F]/u', $rawSearch)) {
            return $this->noStore(new Response('', Response::HTTP_BAD_REQUEST));
        }
        $search = trim($rawSearch);
        if (mb_strlen($search, 'UTF-8') > 100) {
            return $this->noStore(new Response('', Response::HTTP_BAD_REQUEST));
        }

        $statistics->purgeExpired(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $total = $statistics->countForAdmin($search);
        $pageCount = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($pageCount, max(1, min(100_000, $request->query->getInt('page', 1))));

        return $this->noStore($this->render('admin/search_statistics/index.html.twig', [
            'entries' => $statistics->findForAdmin($search, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE),
            'search' => $search,
            'total' => $total,
            'page' => $page,
            'page_count' => $pageCount,
        ]));
    }

    #[Route('/clear', name: 'clear', methods: ['POST'])]
    public function clear(Request $request, SearchTermStatisticsRepository $statistics, ModuleAvailability $modules): Response
    {
        if (!$modules->isEnabled('search')) {
            return $this->noStore(new Response('', Response::HTTP_NOT_FOUND));
        }

        if (!$this->isCsrfTokenValid('admin_search_statistics_clear', $request->request->getString('_token'))) {
            return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
        }

        $deleted = $statistics->clear();
        $this->addFlash('success', sprintf('Suchstatistik geleert. %d Einträge wurden entfernt.', $deleted));

        return $this->noStore($this->redirectToRoute('admin_search_statistics_index'));
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
