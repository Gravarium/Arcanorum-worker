<?php

declare(strict_types=1);

namespace App\Module\Page\Controller;

use App\Entity\PublishingStatus;
use App\Entity\StaticPage;
use App\Repository\ContentInlineImageRepository;
use App\Repository\StaticPageRepository;
use App\Service\ContentInlineImageManager;
use App\Service\RichTextSanitizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\AsciiSlugger;
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
        RichTextSanitizer $richText,
        ContentInlineImageRepository $inlineImages,
        ContentInlineImageManager $inlineImageManager,
    ): Response {
        return $this->editForm($request, null, $pages, $entityManager, $translator, $richText, $inlineImages, $inlineImageManager);
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
        RichTextSanitizer $richText,
        ContentInlineImageRepository $inlineImages,
        ContentInlineImageManager $inlineImageManager,
    ): Response {
        $page = $pages->find($id);
        if (!$page instanceof StaticPage) {
            throw $this->createNotFoundException('Seite wurde nicht gefunden.');
        }

        return $this->editForm($request, $page, $pages, $entityManager, $translator, $richText, $inlineImages, $inlineImageManager);
    }

    private function editForm(
        Request $request,
        ?StaticPage $page,
        StaticPageRepository $pages,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
        RichTextSanitizer $richText,
        ContentInlineImageRepository $inlineImages,
        ContentInlineImageManager $inlineImageManager,
    ): Response {
        $values = [
            'title' => $page?->getTitle() ?? '',
            'slug' => $page?->getSlug() ?? '',
            'summary' => $page?->getSummary() ?? '',
            'body' => $page?->getBody() ?? '',
            'meta_keywords' => $page?->getMetaKeywords() ?? '',
            'meta_description' => $page?->getMetaDescription() ?? '',
            'author_name' => $page?->getAuthorName() ?? 'Redaktion',
            'show_in_menu' => $page?->isShownInMenu() ?? false,
            'status' => $page?->getStatus()->value ?? PublishingStatus::Draft->value,
        ];
        $errors = [];
        $inlineImageIds = [];

        if ($request->isMethod('POST')) {
            $token = self::input($request->request, '_token');
            if ('' === $token || !$this->isCsrfTokenValid('admin_page_save', $token)) {
                throw $this->createAccessDeniedException();
            }

            $payload = $request->request->all();
            $allowedFields = [
                '_token', 'title', 'slug', 'summary', 'body', 'status', 'meta_keywords',
                'meta_description', 'author_name', 'show_in_menu',
            ];
            if ([] !== array_diff(array_keys($payload), $allowedFields)) {
                $errors[] = $translator->trans('Die Anfrage enthält unerwartete Eingabefelder.');
            }
            if ([] !== array_keys($request->files->all())) {
                $errors[] = $translator->trans('Die Anfrage enthält unerwartete Uploadfelder.');
            }
            foreach ($allowedFields as $field) {
                if (isset($payload[$field]) && !is_string($payload[$field])) {
                    $errors[] = $translator->trans('Die Eingabe enthält einen ungültigen Wert.');
                }
            }
            if (isset($payload['show_in_menu']) && '1' !== $payload['show_in_menu']) {
                $errors[] = $translator->trans('Die Eingabe enthält einen ungültigen Wert.');
            }

            $values = [
                'title' => trim(self::input($request->request, 'title')),
                'slug' => trim(self::input($request->request, 'slug')),
                'summary' => trim(self::input($request->request, 'summary')),
                'body' => self::input($request->request, 'body'),
                'meta_keywords' => trim(self::input($request->request, 'meta_keywords')),
                'meta_description' => trim(self::input($request->request, 'meta_description')),
                'author_name' => trim(self::input($request->request, 'author_name')),
                'show_in_menu' => $request->request->has('show_in_menu'),
                'status' => self::input($request->request, 'status'),
            ];
            try {
                $values['body'] = $richText->sanitize($values['body']);
                $inlineImageIds = $richText->inlineImageIds($values['body']);
            } catch (\InvalidArgumentException) {
                $values['body'] = '';
                $errors[] = $translator->trans('Der Inhalt ist zu groß oder enthält ungültiges UTF-8.');
            }
            if (count($inlineImageIds) > 20) {
                $errors[] = $translator->trans('Pro Seite sind höchstens 20 Inline-Bilder zulässig.');
            } elseif ([] !== $inlineImageIds
                && (null === $page || !$inlineImages->allBelongToStaticPage($page->getId(), $inlineImageIds))
            ) {
                $errors[] = $translator->trans('Der Inhalt enthält ein Bild, das nicht zu dieser Seite gehört.');
            }
            $status = PublishingStatus::tryFrom($values['status']);

            if (false === preg_match('//u', $values['title'])
                || '' === $values['title']
                || mb_strlen($values['title']) > 180
                || 1 === preg_match('/\p{Cc}/u', $values['title'])
            ) {
                $errors[] = 'Der Titel ist erforderlich und darf höchstens 180 Zeichen lang sein.';
            }
            if (false === preg_match('//u', $values['summary']) || mb_strlen($values['summary']) > 500) {
                $errors[] = $translator->trans('Die Kurzbeschreibung darf höchstens 500 Zeichen lang sein.');
            }
            foreach (['meta_keywords', 'meta_description'] as $field) {
                if (false === preg_match('//u', $values[$field])
                    || mb_strlen($values[$field]) > 500
                    || 1 === preg_match('/\p{Cc}/u', $values[$field])
                ) {
                    $errors[] = $translator->trans('Meta-Angaben dürfen gültiges UTF-8 ohne Steuerzeichen enthalten und höchstens 500 Zeichen lang sein.');
                }
            }
            if (false === preg_match('//u', $values['author_name'])
                || '' === $values['author_name']
                || mb_strlen($values['author_name']) > 120
                || 1 === preg_match('/\p{Cc}/u', $values['author_name'])
            ) {
                $errors[] = $translator->trans('Die Autorenangabe ist erforderlich und darf höchstens 120 Zeichen lang sein.');
            }
            $plainBody = trim(html_entity_decode(strip_tags($values['body']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (('' === $plainBody && [] === $inlineImageIds) || mb_strlen($values['body']) > 100000) {
                $errors[] = $translator->trans('Der Seiteninhalt ist erforderlich und darf höchstens 100.000 Zeichen lang sein.');
            }
            if (null === $status) {
                $errors[] = 'Bitte wähle einen gültigen Veröffentlichungsstatus.';
            }

            $slug = '' === $values['slug'] ? self::slugify($values['title']) : $values['slug'];
            if (mb_strlen($slug) > 200 || 1 !== preg_match('/\\A[a-z0-9]+(?:-[a-z0-9]+)*\\z/', $slug)) {
                $errors[] = $translator->trans('Der Permalink darf nur Kleinbuchstaben, Ziffern und einzelne Bindestriche enthalten.');
            }
            $existing = $pages->findOneBySlug($slug);
            if (null !== $existing && (null === $page || $existing->getId() !== $page->getId())) {
                $errors[] = $translator->trans('Für diesen Permalink gibt es bereits eine Seite.');
            }

            if ([] === $errors) {
                $entityManager->getConnection()->transactional(function () use ($entityManager, &$page, $pages, $values, $slug, $status, $inlineImageIds, $inlineImageManager): void {
                    if (null !== $page) {
                        $lockedPage = $pages->findByIdForUpdate($page->getId());
                        if (!$lockedPage instanceof StaticPage) {
                            throw $this->createNotFoundException('Seite wurde zwischenzeitlich entfernt.');
                        }
                        $page = $lockedPage;
                        $entityManager->refresh($page, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
                    }

                    if (null === $page) {
                        $page = new StaticPage(
                            $values['title'],
                            $slug,
                            '' === $values['summary'] ? null : $values['summary'],
                            $values['body'],
                            $status,
                            '' === $values['meta_keywords'] ? null : $values['meta_keywords'],
                            '' === $values['meta_description'] ? null : $values['meta_description'],
                            $values['author_name'],
                            $values['show_in_menu'],
                        );
                    } else {
                        $page->revise(
                            $values['title'],
                            $slug,
                            '' === $values['summary'] ? null : $values['summary'],
                            $values['body'],
                            $status,
                            '' === $values['meta_keywords'] ? null : $values['meta_keywords'],
                            '' === $values['meta_description'] ? null : $values['meta_description'],
                            $values['author_name'],
                            $values['show_in_menu'],
                        );
                    }

                    $entityManager->persist($page);
                    $entityManager->flush();
                    $inlineImageManager->removeUnusedPageImages($page, $inlineImageIds);
                    $entityManager->flush();
                });
                $this->addFlash('success', $translator->trans('Seite wurde gespeichert.'));

                return $this->redirectToRoute('admin_page_index');
            }
        }

        return $this->render('admin/pages/form.html.twig', [
            'values' => $values,
            'errors' => $errors,
            'is_edit' => null !== $page,
            'page_id' => $page?->getId(),
            'image_upload_url' => null === $page ? null : $this->generateUrl('admin_content_inline_image_upload', ['type' => 'page', 'ownerId' => $page->getId()]),
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
        $slug = new AsciiSlugger('en')->slug($value)->lower()->toString();

        return '' === $slug ? bin2hex(random_bytes(12)) : mb_substr($slug, 0, 200);
    }
}
