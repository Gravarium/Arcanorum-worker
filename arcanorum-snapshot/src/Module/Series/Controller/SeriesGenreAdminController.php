<?php

declare(strict_types=1);

namespace App\Module\Series\Controller;

use App\Service\SeriesGenreManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/series/genres', name: 'admin_series_genre_')]
#[IsGranted('ROLE_ADMIN')]
final class SeriesGenreAdminController extends AbstractController
{
    private const int PAGE_SIZE = 50;

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, SeriesGenreManager $genres): Response
    {
        $total = $genres->countForAdmin();
        $pageCount = max(1, (int) ceil($total / self::PAGE_SIZE));
        $rawPage = $request->query->all()['page'] ?? '1';
        $page = is_string($rawPage) && ctype_digit($rawPage) ? (int) $rawPage : 1;
        $page = min($pageCount, max(1, $page));

        return $this->noStore($this->render('admin/series/genres.html.twig', [
            'genres' => $genres->listForAdmin(self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE),
            'total' => $total,
            'page' => $page,
            'page_count' => $pageCount,
        ]));
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request, SeriesGenreManager $genres): Response
    {
        $values = $request->request->all();
        $name = $values['name'] ?? null;
        $token = $values['_token'] ?? null;
        if (!is_string($token) || !$this->isCsrfTokenValid('series_genre_create', $token)) {
            return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
        }
        if (!is_string($name) || strlen($name) > 160) {
            return $this->noStore(new Response('', Response::HTTP_BAD_REQUEST));
        }

        try {
            $genres->create($name);
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $this->translator->trans($exception->getMessage()));

            return $this->noStore($this->redirectToRoute('admin_series_genre_index'));
        } catch (UniqueConstraintViolationException) {
            $this->addFlash('error', $this->translator->trans('Das Genre wurde inzwischen bereits angelegt.'));

            return $this->noStore($this->redirectToRoute('admin_series_genre_index'));
        }
        $this->addFlash('success', $this->translator->trans('Seriengenre wurde angelegt.'));

        return $this->noStore($this->redirectToRoute('admin_series_genre_index'));
    }

    #[Route('/rename', name: 'rename', methods: ['POST'])]
    public function rename(Request $request, SeriesGenreManager $genres): Response
    {
        $values = $request->request->all();
        $old = $values['genre'] ?? null;
        $new = $values['new_name'] ?? null;
        $token = $values['_token'] ?? null;
        if (!is_string($old) || strlen($old) > 160 || !is_string($token)
            || !$this->isCsrfTokenValid('series_genre_rename_'.$old, $token)
        ) {
            return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
        }
        if (!is_string($new) || strlen($new) > 160) {
            return $this->noStore(new Response('', Response::HTTP_BAD_REQUEST));
        }

        try {
            $changed = $genres->rename($old, $new);
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $this->translator->trans($exception->getMessage()));

            return $this->noStore($this->redirectToRoute('admin_series_genre_index'));
        } catch (UniqueConstraintViolationException) {
            $this->addFlash('error', $this->translator->trans('Das neue Genre wurde inzwischen bereits vergeben.'));

            return $this->noStore($this->redirectToRoute('admin_series_genre_index'));
        }
        $this->addFlash('success', $this->translator->trans(
            'Genre umbenannt; %count% Serien verwenden es.',
            ['%count%' => $changed],
        ));

        return $this->noStore($this->redirectToRoute('admin_series_genre_index'));
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, SeriesGenreManager $genres): Response
    {
        $values = $request->request->all();
        $old = $values['genre'] ?? null;
        $token = $values['_token'] ?? null;
        if (!is_string($old) || strlen($old) > 160 || !is_string($token)
            || !$this->isCsrfTokenValid('series_genre_delete_'.$old, $token)
        ) {
            return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
        }
        if ('1' !== ($values['confirm_delete'] ?? null)) {
            $this->addFlash('error', $this->translator->trans('Bestätige das Entfernen des Genres aus allen Serien.'));

            return $this->noStore($this->redirectToRoute('admin_series_genre_index'));
        }

        try {
            $changed = $genres->delete($old);
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $this->translator->trans($exception->getMessage()));

            return $this->noStore($this->redirectToRoute('admin_series_genre_index'));
        }
        $this->addFlash('success', $this->translator->trans(
            'Genre aus %count% Serien entfernt.',
            ['%count%' => $changed],
        ));

        return $this->noStore($this->redirectToRoute('admin_series_genre_index'));
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
