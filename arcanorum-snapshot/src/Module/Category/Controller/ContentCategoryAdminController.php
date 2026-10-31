<?php

declare(strict_types=1);

namespace App\Module\Category\Controller;

use App\Entity\ContentCategory;
use App\Entity\ContentCategoryType;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use App\Repository\ContentCategoryRepository;
use App\Repository\ContentInlineImageRepository;
use App\Service\ContentInlineImageManager;
use App\Service\RichTextSanitizer;
use App\Service\VideoCategoryArtworkManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/categories', name: 'admin_category_')]
#[IsGranted('ROLE_CONTENT_EDITOR')]
final class ContentCategoryAdminController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, ContentCategoryRepository $categories): Response
    {
        $rawType = self::input($request->query, 'type');
        $type = ContentCategoryType::tryFrom($rawType);
        if ($this->isNewsOnlyEditor()) {
            if ('' === $rawType) {
                $type = ContentCategoryType::News;
            } elseif (ContentCategoryType::News !== $type) {
                throw $this->createAccessDeniedException();
            }
        }
        $categoryList = $categories->findForAdmin($type);
        $usageCounts = [];
        $childCounts = [];
        foreach ($categoryList as $category) {
            $usageCounts[$category->getId()] = $categories->countUsage($category);
            $childCounts[$category->getId()] = $categories->countChildren($category);
        }

        return $this->render('admin/categories/index.html.twig', [
            'categories' => $categoryList,
            'usage_counts' => $usageCounts,
            'child_counts' => $childCounts,
            'type' => self::typeValue($type),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        ContentCategoryRepository $categories,
        RichTextSanitizer $richText,
        EntityManagerInterface $entityManager,
    ): Response {
        $rawQueryType = self::input($request->query, 'type');
        $queryType = ContentCategoryType::tryFrom($rawQueryType);
        if ($this->isNewsOnlyEditor()) {
            if ('' === $rawQueryType) {
                $queryType = ContentCategoryType::News;
            } elseif (ContentCategoryType::News !== $queryType) {
                throw $this->createAccessDeniedException();
            }
        }
        $values = [
            'name' => '',
            'type' => self::typeValue($queryType),
            'slug' => '',
            'description' => '',
            'meta_title' => '',
            'meta_description' => '',
            'meta_keywords' => '',
            'sort_order' => '0',
            'parent_id' => '',
            'featured' => '0',
        ];
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_category_save', self::input($request->request, '_token'))) {
                throw $this->createAccessDeniedException();
            }

            $values = [
                'name' => trim(self::input($request->request, 'name')),
                'type' => self::input($request->request, 'type'),
                'slug' => trim(self::input($request->request, 'slug')),
                'description' => '',
                'meta_title' => trim(self::input($request->request, 'meta_title')),
                'meta_description' => trim(self::input($request->request, 'meta_description')),
                'meta_keywords' => trim(self::input($request->request, 'meta_keywords')),
                'sort_order' => trim(self::input($request->request, 'sort_order')),
                'parent_id' => trim(self::input($request->request, 'parent_id')),
                'featured' => self::featuredInput($request, $errors),
            ];
            $values['description'] = self::sanitizeDescriptionInput($request, $richText, $errors);
            if ([] !== $richText->inlineImageIds($values['description'])) {
                $errors[] = 'Inline-Bilder können erst eingefügt werden, nachdem die Kategorie gespeichert wurde.';
            }
            $type = ContentCategoryType::tryFrom($values['type']);
            if ($this->isNewsOnlyEditor() && ContentCategoryType::News !== $type) {
                throw $this->createAccessDeniedException();
            }

            if (self::containsControlCharacters($values['name']) || '' === $values['name'] || mb_strlen($values['name']) > 100) {
                $errors[] = 'Der Kategoriename ist erforderlich und darf höchstens 100 Zeichen lang sein.';
            }
            if (null === $type) {
                $errors[] = 'Bitte wähle News oder Video als Inhaltsbereich.';
            }
            if (ContentCategoryType::Video !== $type && '1' === $values['featured']) {
                $errors[] = 'Nur Videokategorien können auf der Startseite hervorgehoben werden.';
            }
            if (null !== $type
                && '' !== $values['name']
                && !self::containsControlCharacters($values['name'])
                && mb_strlen($values['name']) <= 100
                && null !== $categories->findForTypeAndName($type, $values['name'])
            ) {
                $errors[] = 'Diese Kategorie gibt es in diesem Inhaltsbereich bereits.';
            }

            $slug = '' === $values['slug']
                ? (self::containsControlCharacters($values['name']) ? '' : ContentCategory::slugFromName($values['name']))
                : $values['slug'];
            $values['slug'] = $slug;
            $sortOrder = self::validateTaxonomyValues($values, $errors);
            if (null !== $type && null !== $categories->findForTypeAndSlug($type, $slug)) {
                $errors[] = 'Dieser Kategorien-Slug wird in diesem Inhaltsbereich bereits verwendet.';
            }

            $parent = null;
            if ('' !== $values['parent_id']) {
                $candidate = 1 === preg_match('/\A[a-f0-9]{32}\z/', $values['parent_id'])
                    ? $categories->find($values['parent_id'])
                    : null;
                if (!$candidate instanceof ContentCategory || null === $type
                    || $candidate->getContentType() !== $type
                    || $candidate->getDepth() >= ContentCategory::MAX_DEPTH) {
                    $errors[] = 'Die übergeordnete Kategorie ist ungültig.';
                } else {
                    $parent = $candidate;
                }
            }

            if ([] === $errors && null !== $type && null !== $sortOrder) {
                $category = new ContentCategory($values['name'], $type);
                $category->setParent($parent);
                $category->setFeatured('1' === $values['featured']);
                $category->updateTaxonomy(
                    $slug,
                    $values['description'],
                    self::optionalText($values['meta_title']),
                    self::optionalText($values['meta_description']),
                    self::optionalText($values['meta_keywords']),
                    $sortOrder,
                );
                $entityManager->persist($category);
                $entityManager->flush();
                $this->addFlash('success', 'Kategorie wurde angelegt.');

                return $this->redirectToRoute('admin_category_index', ['type' => $type->value]);
            }
        }

        $displayType = ContentCategoryType::tryFrom($values['type']);

        return $this->render('admin/categories/form.html.twig', [
            'values' => $values,
            'errors' => $errors,
            'is_edit' => false,
            'category_types' => $this->isNewsOnlyEditor() ? [ContentCategoryType::News] : ContentCategoryType::cases(),
            'type_label' => null,
            'parent_categories' => null !== $displayType ? $categories->findPotentialParents($displayType) : [],
            'category_id' => null,
            'artwork_exists' => false,
            'image_upload_type' => null,
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
        ContentInlineImageRepository $inlineImages,
        ContentInlineImageManager $imageManager,
        EntityManagerInterface $entityManager,
        RichTextSanitizer $richText,
        TranslatorInterface $translator,
    ): Response {
        $category = $categories->find($id);
        if (!$category instanceof ContentCategory) {
            throw $this->createNotFoundException($translator->trans('Kategorie wurde nicht gefunden.'));
        }
        if ($this->isNewsOnlyEditor() && ContentCategoryType::News !== $category->getContentType()) {
            throw $this->createAccessDeniedException();
        }

        $parent = $category->getParent();
        $values = [
            'name' => $category->getName(),
            'type' => $category->getContentType()->value,
            'slug' => $category->getSlug(),
            'description' => self::safeStoredDescription($category->getDescription(), $richText),
            'meta_title' => $category->getMetaTitle() ?? '',
            'meta_description' => $category->getMetaDescription() ?? '',
            'meta_keywords' => $category->getMetaKeywords() ?? '',
            'sort_order' => (string) $category->getSortOrder(),
            'parent_id' => $parent?->getId() ?? '',
            'featured' => $category->isFeatured() ? '1' : '0',
        ];
        $errors = [];
        $inlineImageIds = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_category_save', self::input($request->request, '_token'))) {
                throw $this->createAccessDeniedException();
            }

            $values = [
                'name' => trim(self::input($request->request, 'name')),
                'type' => $category->getContentType()->value,
                'slug' => trim(self::input($request->request, 'slug')),
                'description' => '',
                'meta_title' => trim(self::input($request->request, 'meta_title')),
                'meta_description' => trim(self::input($request->request, 'meta_description')),
                'meta_keywords' => trim(self::input($request->request, 'meta_keywords')),
                'sort_order' => trim(self::input($request->request, 'sort_order')),
                'parent_id' => trim(self::input($request->request, 'parent_id')),
                'featured' => self::featuredInput($request, $errors),
            ];
            $values['description'] = self::sanitizeDescriptionInput($request, $richText, $errors);
            $inlineImageIds = $richText->inlineImageIds($values['description']);
            if (!$inlineImages->allBelongToCategory($category->getId(), $inlineImageIds)) {
                $errors[] = 'Die Beschreibung darf nur Inline-Bilder dieser Kategorie verwenden.';
            }
            if (ContentCategoryType::Video !== $category->getContentType() && '1' === $values['featured']) {
                $errors[] = 'Nur Videokategorien können auf der Startseite hervorgehoben werden.';
            }
            $name = $values['name'];
            $slug = '' === $values['slug'] ? $category->getSlug() : $values['slug'];
            $values['slug'] = $slug;
            if (self::containsControlCharacters($name) || '' === $name || mb_strlen($name) > 100) {
                $errors[] = 'Der Kategoriename ist erforderlich und darf höchstens 100 Zeichen lang sein.';
            }

            $existing = '' === $name || self::containsControlCharacters($name) || mb_strlen($name) > 100
                ? null
                : $categories->findForTypeAndName($category->getContentType(), $name);
            if (null !== $existing && $existing->getId() !== $category->getId()) {
                $errors[] = 'Diese Kategorie gibt es in diesem Inhaltsbereich bereits.';
            }

            $sortOrder = self::validateTaxonomyValues($values, $errors);
            $existingSlug = $categories->findForTypeAndSlug($category->getContentType(), $slug);
            if (null !== $existingSlug && $existingSlug->getId() !== $category->getId()) {
                $errors[] = 'Dieser Kategorien-Slug wird in diesem Inhaltsbereich bereits verwendet.';
            }

            $parent = null;
            if ('' !== $values['parent_id']) {
                $candidate = 1 === preg_match('/\A[a-f0-9]{32}\z/', $values['parent_id'])
                    ? $categories->find($values['parent_id'])
                    : null;
                if (!$candidate instanceof ContentCategory || !$categories->canMoveToParent($category, $candidate)) {
                    $errors[] = 'Die übergeordnete Kategorie ist ungültig.';
                } else {
                    $parent = $candidate;
                }
            }

            if ([] === $errors && null !== $sortOrder) {
                $saved = $entityManager->getConnection()->transactional(function () use ($category, $name, $slug, $values, $sortOrder, $parent, $categories, $inlineImages, $entityManager, $imageManager, $inlineImageIds): bool {
                    $lockedCategory = $categories->findForUpdate($category->getId());
                    if (!$lockedCategory instanceof ContentCategory) {
                        throw new \RuntimeException('Die Kategorie wurde während der Bearbeitung entfernt.');
                    }
                    if (!$inlineImages->allBelongToCategory($lockedCategory->getId(), $inlineImageIds)) {
                        return false;
                    }
                    if ($name !== $lockedCategory->getName()) {
                        $categories->renameAssignedContent($lockedCategory, $name);
                        $lockedCategory->rename($name);
                    }
                    $lockedCategory->setParent($parent);
                    $lockedCategory->setFeatured('1' === $values['featured']);
                    $lockedCategory->updateTaxonomy(
                        $slug,
                        $values['description'],
                        self::optionalText($values['meta_title']),
                        self::optionalText($values['meta_description']),
                        self::optionalText($values['meta_keywords']),
                        $sortOrder,
                    );
                    $imageManager->removeUnusedCategoryImages($lockedCategory, $inlineImageIds);
                    $entityManager->flush();

                    return true;
                });

                if (!$saved) {
                    $errors[] = 'Die Beschreibung darf nur Inline-Bilder dieser Kategorie verwenden.';
                } else {
                    $this->addFlash('success', 'Kategorie wurde gespeichert.');

                    return $this->redirectToRoute('admin_category_index', ['type' => $category->getContentType()->value]);
                }
            }
        }

        return $this->render('admin/categories/form.html.twig', [
            'values' => $values,
            'errors' => $errors,
            'is_edit' => true,
            'category_types' => $this->isNewsOnlyEditor() ? [ContentCategoryType::News] : ContentCategoryType::cases(),
            'type_label' => $category->getContentType()->getLabel(),
            'parent_categories' => $categories->findPotentialParents($category->getContentType(), $category),
            'category_id' => $category->getId(),
            'artwork_exists' => null !== $category->getArtworkObjectId(),
            'image_upload_type' => ContentCategoryType::News === $category->getContentType() ? 'news-category' : 'video-category',
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route(
        '/{id}/artwork',
        name: 'artwork',
        requirements: ['id' => '[a-f0-9]{32}'],
        methods: ['POST'],
    )]
    public function artwork(
        string $id,
        Request $request,
        ContentCategoryRepository $categories,
        VideoCategoryArtworkManager $artworks,
        TranslatorInterface $translator,
    ): Response {
        $category = $categories->find($id);
        if (!$category instanceof ContentCategory) {
            throw $this->createNotFoundException($translator->trans('Kategorie wurde nicht gefunden.'));
        }
        if (ContentCategoryType::Video !== $category->getContentType() || $this->isNewsOnlyEditor()) {
            throw $this->createAccessDeniedException();
        }

        $token = self::input($request->request, '_token');
        if ('' === $token || !$this->isCsrfTokenValid('admin_category_artwork_'.$category->getId(), $token)) {
            throw $this->createAccessDeniedException();
        }

        $action = self::input($request->request, 'action');
        if ('remove' === $action) {
            if (null !== $request->files->get('artwork')) {
                $this->addFlash('error', $translator->trans('Beim Entfernen darf keine Datei mitgesendet werden.'));

                return $this->redirectToRoute('admin_category_edit', ['id' => $category->getId()]);
            }
            $artworks->remove($category->getId());
            $this->addFlash('success', $translator->trans('Kategorie-Artwork wurde entfernt.'));

            return $this->redirectToRoute('admin_category_edit', ['id' => $category->getId()]);
        }

        if ('upload' !== $action) {
            $this->addFlash('error', $translator->trans('Bitte wähle eine gültige Artwork-Aktion aus.'));

            return $this->redirectToRoute('admin_category_edit', ['id' => $category->getId()]);
        }

        $upload = $request->files->get('artwork');
        if (!$upload instanceof UploadedFile) {
            $this->addFlash('error', $translator->trans('Bitte wähle eine gültige Bilddatei aus.'));

            return $this->redirectToRoute('admin_category_edit', ['id' => $category->getId()]);
        }

        try {
            $artworks->replace($category->getId(), $upload);
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $translator->trans($exception->getMessage()));

            return $this->redirectToRoute('admin_category_edit', ['id' => $category->getId()]);
        } catch (PrivateStorageOperationFailed) {
            $this->addFlash('error', $translator->trans('Der sichere private Dateispeicher ist nicht verfügbar; das Artwork wurde nicht gespeichert.'));

            return $this->redirectToRoute('admin_category_edit', ['id' => $category->getId()]);
        }

        $this->addFlash('success', $translator->trans('Kategorie-Artwork wurde gespeichert.'));

        return $this->redirectToRoute('admin_category_edit', ['id' => $category->getId()]);
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
        VideoCategoryArtworkManager $artworks,
        ContentInlineImageManager $imageManager,
        TranslatorInterface $translator,
    ): Response {
        $category = $categories->find($id);
        if (!$category instanceof ContentCategory) {
            throw $this->createNotFoundException($translator->trans('Kategorie wurde nicht gefunden.'));
        }
        if ($this->isNewsOnlyEditor() && ContentCategoryType::News !== $category->getContentType()) {
            throw $this->createAccessDeniedException();
        }

        $token = self::input($request->request, '_token');
        if (!$this->isCsrfTokenValid('admin_category_delete_'.$category->getId(), $token)) {
            throw $this->createAccessDeniedException();
        }

        $type = $category->getContentType()->value;
        if ($categories->countUsage($category) > 0 || $categories->countChildren($category) > 0) {
            $this->addFlash('error', 'Die Kategorie wird noch verwendet oder enthält Unterkategorien und kann nicht gelöscht werden.');

            return $this->redirectToRoute('admin_category_index', ['type' => $type]);
        }

        if (null !== $category->getArtworkObjectId()) {
            $artworks->remove($category->getId());
        }
        $entityManager->getConnection()->transactional(function () use ($category, $categories, $entityManager, $imageManager): void {
            $lockedCategory = $categories->findForUpdate($category->getId());
            if (!$lockedCategory instanceof ContentCategory) {
                throw new \RuntimeException('Die Kategorie wurde während des Löschens entfernt.');
            }
            $imageManager->removeAllCategoryImages($lockedCategory);
            $entityManager->remove($lockedCategory);
            $entityManager->flush();
        });
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
     * @param array<string, string> $values
     * @param list<string>          $errors
     */
    private static function validateTaxonomyValues(array $values, array &$errors): ?int
    {
        $slug = $values['slug'];
        if (1 !== preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) || mb_strlen($slug) > 120) {
            $errors[] = 'Der Kategorien-Slug darf nur Kleinbuchstaben, Zahlen und Bindestriche enthalten.';
        }

        foreach ([
            'description' => [2000, 'Die Beschreibung darf höchstens 2000 Zeichen lang sein.'],
            'meta_title' => [160, 'Der Meta-Titel darf höchstens 160 Zeichen lang sein.'],
            'meta_description' => [500, 'Die Meta-Beschreibung darf höchstens 500 Zeichen lang sein.'],
            'meta_keywords' => [255, 'Die Meta-Schlagwörter dürfen höchstens 255 Zeichen lang sein.'],
        ] as $key => [$maximum, $message]) {
            $value = $values[$key];
            if (self::containsControlCharacters($value) || mb_strlen($value) > $maximum) {
                $errors[] = $message;
            }
        }

        $sortOrder = $values['sort_order'];
        if (!ctype_digit($sortOrder) || (int) $sortOrder > 10000) {
            $errors[] = 'Die Sortierung muss eine Zahl zwischen 0 und 10000 sein.';

            return null;
        }

        return (int) $sortOrder;
    }

    private static function optionalText(string $value): ?string
    {
        $value = trim($value);

        return '' === $value ? null : $value;
    }

    /** @param list<string> $errors */
    private static function sanitizeDescription(string $description, RichTextSanitizer $sanitizer, array &$errors): string
    {
        try {
            return $sanitizer->sanitize(trim($description));
        } catch (\InvalidArgumentException) {
            $errors[] = 'Die Beschreibung ist zu groß oder enthält ungültigen Text.';

            return '';
        }
    }

    /** @param list<string> $errors */
    private static function sanitizeDescriptionInput(Request $request, RichTextSanitizer $sanitizer, array &$errors): string
    {
        $description = $request->request->all()['description'] ?? '';
        if (!is_string($description)) {
            $errors[] = 'Die Beschreibung ist ungültig.';

            return '';
        }

        return self::sanitizeDescription($description, $sanitizer, $errors);
    }

    private static function safeStoredDescription(string $description, RichTextSanitizer $sanitizer): string
    {
        try {
            return $sanitizer->sanitize($description);
        } catch (\InvalidArgumentException) {
            return '';
        }
    }

    /** @param list<string> $errors */
    private static function featuredInput(Request $request, array &$errors): string
    {
        $value = $request->request->all()['featured'] ?? '0';
        if (!is_string($value) || !in_array($value, ['0', '1'], true)) {
            $errors[] = 'Der Startseitenstatus der Kategorie ist ungültig.';

            return '0';
        }

        return $value;
    }

    private static function containsControlCharacters(string $value): bool
    {
        return 1 !== preg_match('//u', $value)
            || 1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value);
    }

    private function isNewsOnlyEditor(): bool
    {
        return $this->isGranted('ROLE_CONTENT_EDITOR') && !$this->isGranted('ROLE_ADMIN');
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
