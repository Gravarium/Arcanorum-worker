<?php

declare(strict_types=1);

namespace App\Module\Category\Controller;

use App\Entity\ContentCategory;
use App\Entity\ContentCategoryType;
use App\Repository\ContentCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/categories', name: 'admin_category_')]
#[IsGranted('ROLE_ADMIN')]
final class ContentCategoryAdminController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, ContentCategoryRepository $categories): Response
    {
        $type = ContentCategoryType::tryFrom(self::input($request->query, 'type'));
        $categoryList = $categories->findForAdmin($type);
        $usageCounts = [];
        foreach ($categoryList as $category) {
            $usageCounts[$category->getId()] = $categories->countUsage($category);
        }

        return $this->render('admin/categories/index.html.twig', [
            'categories' => $categoryList,
            'usage_counts' => $usageCounts,
            'type' => self::typeValue($type),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        ContentCategoryRepository $categories,
        EntityManagerInterface $entityManager,
    ): Response {
        $queryType = ContentCategoryType::tryFrom(self::input($request->query, 'type'));
        $values = [
            'name' => '',
            'type' => self::typeValue($queryType),
        ];
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_category_save', self::input($request->request, '_token'))) {
                throw $this->createAccessDeniedException();
            }

            $values = [
                'name' => trim(self::input($request->request, 'name')),
                'type' => self::input($request->request, 'type'),
            ];
            $type = ContentCategoryType::tryFrom($values['type']);

            if ('' === $values['name'] || mb_strlen($values['name']) > 100) {
                $errors[] = 'Der Kategoriename ist erforderlich und darf höchstens 100 Zeichen lang sein.';
            }
            if (null === $type) {
                $errors[] = 'Bitte wähle News oder Video als Inhaltsbereich.';
            }
            if (null !== $type && null !== $categories->findForTypeAndName($type, $values['name'])) {
                $errors[] = 'Diese Kategorie gibt es in diesem Inhaltsbereich bereits.';
            }

            if ([] === $errors && null !== $type) {
                $category = new ContentCategory($values['name'], $type);
                $entityManager->persist($category);
                $entityManager->flush();
                $this->addFlash('success', 'Kategorie wurde angelegt.');

                return $this->redirectToRoute('admin_category_index', ['type' => $type->value]);
            }
        }

        return $this->render('admin/categories/form.html.twig', [
            'values' => $values,
            'errors' => $errors,
            'is_edit' => false,
            'category_types' => ContentCategoryType::cases(),
            'type_label' => null,
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
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
        ContentCategoryRepository $categories,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
    ): Response {
        $category = $categories->find($id);
        if (!$category instanceof ContentCategory) {
            throw $this->createNotFoundException($translator->trans('Kategorie wurde nicht gefunden.'));
        }

        $values = ['name' => $category->getName(), 'type' => $category->getContentType()->value];
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_category_save', self::input($request->request, '_token'))) {
                throw $this->createAccessDeniedException();
            }

            $name = trim(self::input($request->request, 'name'));
            if ('' === $name || mb_strlen($name) > 100) {
                $errors[] = 'Der Kategoriename ist erforderlich und darf höchstens 100 Zeichen lang sein.';
            }

            $existing = '' === $name
                ? null
                : $categories->findForTypeAndName($category->getContentType(), $name);
            if (null !== $existing && $existing->getId() !== $category->getId()) {
                $errors[] = 'Diese Kategorie gibt es in diesem Inhaltsbereich bereits.';
            }

            if ([] === $errors) {
                if ($name !== $category->getName()) {
                    $entityManager->getConnection()->transactional(function () use ($category, $name, $categories, $entityManager): void {
                        $categories->renameAssignedContent($category, $name);
                        $category->rename($name);
                        $entityManager->flush();
                    });
                }

                $this->addFlash('success', 'Kategorie wurde gespeichert.');

                return $this->redirectToRoute('admin_category_index', ['type' => $category->getContentType()->value]);
            }

            $values['name'] = $name;
        }

        return $this->render('admin/categories/form.html.twig', [
            'values' => $values,
            'errors' => $errors,
            'is_edit' => true,
            'category_types' => ContentCategoryType::cases(),
            'type_label' => $category->getContentType()->getLabel(),
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route(
        '/{id}/delete',
        name: 'delete',
        requirements: ['id' => '[a-f0-9]{32}'],
        methods: ['POST'],
    )]
    public function delete(
        string $id,
        Request $request,
        ContentCategoryRepository $categories,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
    ): Response {
        $category = $categories->find($id);
        if (!$category instanceof ContentCategory) {
            throw $this->createNotFoundException($translator->trans('Kategorie wurde nicht gefunden.'));
        }

        $token = self::input($request->request, '_token');
        if (!$this->isCsrfTokenValid('admin_category_delete_'.$category->getId(), $token)) {
            throw $this->createAccessDeniedException();
        }

        $type = $category->getContentType()->value;
        if ($categories->countUsage($category) > 0) {
            $this->addFlash('error', 'Die Kategorie wird noch verwendet und kann nicht gelöscht werden.');

            return $this->redirectToRoute('admin_category_index', ['type' => $type]);
        }

        $entityManager->remove($category);
        $entityManager->flush();
        $this->addFlash('success', 'Kategorie wurde gelöscht.');

        return $this->redirectToRoute('admin_category_index', ['type' => $type]);
    }

    private static function typeValue(?ContentCategoryType $type): string
    {
        if (null === $type) {
            return '';
        }

        return $type->value;
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
}
