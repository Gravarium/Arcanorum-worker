<?php

declare(strict_types=1);

namespace App\Module\Page\Controller;

use App\Entity\PublishingStatus;
use App\Entity\StaticPage;
use App\Repository\StaticPageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/pages', name: 'admin_page_')]
#[IsGranted('ROLE_ADMIN')]
final class PageAdminController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, StaticPageRepository $pages): Response
    {
        $search = trim(self::input($request->query, 'q'));
        $status = PublishingStatus::tryFrom(self::input($request->query, 'status'));

        return $this->render('admin/pages/index.html.twig', [
            'pages' => $pages->findForAdmin($search, $status),
            'search' => $search,
            'status' => $status instanceof PublishingStatus ? $status->value : '',
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        StaticPageRepository $pages,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
    ): Response {
        return $this->editForm($request, null, $pages, $entityManager, $translator);
    }

    #[Route(
        '/{id}/edit',
        name: 'edit',
        requirements: ['id' => '[a-f0-9]{32}'],
        methods: ['GET', 'POST'],
    )]
    public function edit(
        string $id,
        Request $request,
        StaticPageRepository $pages,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
    ): Response {
        $page = $pages->find($id);
        if (!$page instanceof StaticPage) {
            throw $this->createNotFoundException('Seite wurde nicht gefunden.');
        }

        return $this->editForm($request, $page, $pages, $entityManager, $translator);
    }

    private function editForm(
        Request $request,
        ?StaticPage $page,
        StaticPageRepository $pages,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
    ): Response {
        $values = [
            'title' => $page?->getTitle() ?? '',
            'summary' => $page?->getSummary() ?? '',
            'body' => $page?->getBody() ?? '',
            'status' => $page?->getStatus()->value ?? PublishingStatus::Draft->value,
        ];
        $errors = [];

        if ($request->isMethod('POST')) {
            $token = self::input($request->request, '_token');
            if ('' === $token || !$this->isCsrfTokenValid('admin_page_save', $token)) {
                throw $this->createAccessDeniedException();
            }

            $values = [
                'title' => trim(self::input($request->request, 'title')),
                'summary' => trim(self::input($request->request, 'summary')),
                'body' => self::input($request->request, 'body'),
                'status' => self::input($request->request, 'status'),
            ];
            $status = PublishingStatus::tryFrom($values['status']);

            if ('' === $values['title'] || mb_strlen($values['title']) > 180) {
                $errors[] = 'Der Titel ist erforderlich und darf höchstens 180 Zeichen lang sein.';
            }
            if (mb_strlen($values['summary']) > 500) {
                $errors[] = $translator->trans('Die Kurzbeschreibung darf höchstens 500 Zeichen lang sein.');
            }
            if ('' === trim($values['body']) || mb_strlen($values['body']) > 100000) {
                $errors[] = $translator->trans('Der Seiteninhalt ist erforderlich und darf höchstens 100.000 Zeichen lang sein.');
            }
            if (null === $status) {
                $errors[] = 'Bitte wähle einen gültigen Veröffentlichungsstatus.';
            }

            $slug = self::slugify($values['title']);
            $existing = $pages->findOneBySlug($slug);
            if (null !== $existing && (null === $page || $existing->getId() !== $page->getId())) {
                $errors[] = $translator->trans('Für diesen Titel gibt es bereits eine Seite.');
            }

            if ([] === $errors) {
                if (null === $page) {
                    $page = new StaticPage(
                        $values['title'],
                        $slug,
                        '' === $values['summary'] ? null : $values['summary'],
                        $values['body'],
                        $status,
                    );
                } else {
                    $page->revise(
                        $values['title'],
                        $slug,
                        '' === $values['summary'] ? null : $values['summary'],
                        $values['body'],
                        $status,
                    );
                }

                $entityManager->persist($page);
                $entityManager->flush();
                $this->addFlash('success', $translator->trans('Seite wurde gespeichert.'));

                return $this->redirectToRoute('admin_page_index');
            }
        }

        return $this->render('admin/pages/form.html.twig', [
            'values' => $values,
            'errors' => $errors,
            'is_edit' => null !== $page,
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * @template TInput of string|int|float|bool|null
     *
     * @param InputBag<TInput> $bag
     */
    private static function input(InputBag $bag, string $key): string
    {
        $value = $bag->get($key);

        return is_string($value) ? $value : '';
    }

    private static function slugify(string $value): string
    {
        $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', mb_strtolower(trim($value))) ?? '';
        $slug = trim($slug, '-');

        return '' === $slug ? bin2hex(random_bytes(12)) : mb_substr($slug, 0, 200);
    }
}
