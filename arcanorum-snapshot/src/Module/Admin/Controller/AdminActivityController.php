<?php

declare(strict_types=1);

namespace App\Module\Admin\Controller;

use App\Repository\AdminActivityEntryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/activity', name: 'admin_activity_')]
final class AdminActivityController extends AbstractController
{
    private const int PAGE_SIZE = 50;

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, AdminActivityEntryRepository $activities): Response
    {
        $rawSearch = $request->query->get('q');
        $search = is_string($rawSearch) && 1 === preg_match('//u', $rawSearch)
            ? mb_substr(trim($rawSearch), 0, 120, 'UTF-8')
            : '';
        $page = max(1, min(100_000, $request->query->getInt('page', 1)));
        $total = $activities->countForAdmin($search);
        $pageCount = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($page, $pageCount);

        return $this->noStore($this->render('admin/activity/index.html.twig', [
            'entries' => $activities->findForAdmin($search, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE),
            'search' => $search,
            'total' => $total,
            'page' => $page,
            'page_count' => $pageCount,
        ]));
    }

    #[Route('/clear', name: 'clear', methods: ['POST'])]
    public function clear(Request $request, AdminActivityEntryRepository $activities): Response
    {
        if (!$this->isCsrfTokenValid('admin_activity_clear', $request->request->getString('_token'))) {
            return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
        }

        $deleted = $activities->deleteAll();
        $this->addFlash('success', sprintf('Aktivitätsverlauf geleert. %d Einträge wurden entfernt.', $deleted));

        return $this->noStore($this->redirectToRoute('admin_activity_index'));
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
