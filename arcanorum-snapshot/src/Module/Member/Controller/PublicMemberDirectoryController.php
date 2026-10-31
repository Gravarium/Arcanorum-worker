<?php

declare(strict_types=1);

namespace App\Module\Member\Controller;

use App\Repository\MemberAccountRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicMemberDirectoryController extends AbstractController
{
    private const int PAGE_SIZE = 24;

    #[Route('/mitglieder', name: 'public_member_index', methods: ['GET'])]
    public function index(Request $request, MemberAccountRepository $members): Response
    {
        $query = $request->query->all();
        $rawSearch = $query['q'] ?? '';
        if (!is_string($rawSearch)
            || strlen($rawSearch) > 320
            || 1 !== preg_match('//u', $rawSearch)
            || 1 === preg_match('/[\x00-\x1F\x7F]/u', $rawSearch)
        ) {
            return $this->noStore(new Response('', Response::HTTP_BAD_REQUEST));
        }

        $search = trim($rawSearch);
        if (mb_strlen($search, 'UTF-8') > 80) {
            return $this->noStore(new Response('', Response::HTTP_BAD_REQUEST));
        }

        $rawPage = $query['page'] ?? '1';
        if (!is_string($rawPage)) {
            return $this->noStore(new Response('', Response::HTTP_BAD_REQUEST));
        }
        $page = ctype_digit($rawPage)
            ? max(1, min(5000, (int) $rawPage))
            : 1;
        $total = $members->countPublic($search);
        $pageCount = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($page, $pageCount);

        return $this->noStore($this->render('member/directory.html.twig', [
            'members' => $members->findPublic($search, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE),
            'search' => $search,
            'total' => $total,
            'page' => $page,
            'page_count' => $pageCount,
        ]));
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
