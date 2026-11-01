<?php

declare(strict_types=1);

namespace App\Module\Tag\Controller;

use App\Entity\ContentTag;
use App\Repository\ContentTagRepository;
use App\Service\ContentTagNameLock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/tags', name: 'admin_tag_')]
#[IsGranted('ROLE_ADMIN')]
final class ContentTagAdminController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(ContentTagRepository $tags): Response
    {
        $tagList = $tags->findForAdmin();
        $usageCounts = [];
        foreach ($tagList as $tag) {
            $usageCounts[$tag->getId()] = $tags->countUsage($tag);
        }

        return $this->render('admin/tags/index.html.twig', [
            'tags' => $tagList,
            'usage_counts' => $usageCounts,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        ContentTagRepository $tags,
        EntityManagerInterface $entityManager,
        ContentTagNameLock $nameLock,
    ): Response {
        $values = ['name' => ''];
        $errors = [];

        if ($request->isMethod('POST')) {
            $token = self::input($request->request, '_token');
            if ('' === $token || !$this->isCsrfTokenValid('admin_tag_save', $token)) {
                throw $this->createAccessDeniedException();
            }

            $name = trim(self::input($request->request, 'name'));
            if (!self::isValidName($name)) {
                $errors[] = 'Der Schlagwortname ist erforderlich und darf höchstens 100 Zeichen lang sein.';
            }
            if ('' !== $name && null !== $tags->findByNormalizedName($name)) {
                $errors[] = 'Dieses Schlagwort ist bereits vorhanden.';
            }

            if ([] === $errors) {
                $tag = new ContentTag($name);
                $created = $entityManager->getConnection()->transactional(function () use ($entityManager, $nameLock, $tags, $name, $tag): bool {
                    $nameLock->lockNames($entityManager->getConnection(), [$name]);
                    if (null !== $tags->findByNormalizedName($name)) {
                        return false;
                    }

                    $entityManager->persist($tag);
                    $entityManager->flush();

                    return true;
                });

                if ($created) {
                    $this->addFlash('success', 'Schlagwort wurde angelegt.');

                    return $this->redirectToRoute('admin_tag_index');
                }

                $errors[] = 'Dieses Schlagwort ist bereits vorhanden.';
            }

            $values['name'] = $name;
        }

        return $this->render('admin/tags/form.html.twig', [
            'values' => $values,
            'errors' => $errors,
            'is_edit' => false,
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
        ContentTagRepository $tags,
        EntityManagerInterface $entityManager,
        ContentTagNameLock $nameLock,
    ): Response {
        $tag = $tags->find($id);
        if (!$tag instanceof ContentTag) {
            throw $this->createNotFoundException('Schlagwort wurde nicht gefunden.');
        }

        $values = ['name' => $tag->getName()];
        $errors = [];
        if ($request->isMethod('POST')) {
            $token = self::input($request->request, '_token');
            if ('' === $token || !$this->isCsrfTokenValid('admin_tag_save', $token)) {
                throw $this->createAccessDeniedException();
            }

            $name = trim(self::input($request->request, 'name'));
            if (!self::isValidName($name)) {
                $errors[] = 'Der Schlagwortname ist erforderlich und darf höchstens 100 Zeichen lang sein.';
            }

            $existing = '' === $name ? null : $tags->findByNormalizedName($name);
            if (null !== $existing && $existing->getId() !== $tag->getId()) {
                $errors[] = 'Dieses Schlagwort ist bereits vorhanden.';
            }

            if ([] === $errors) {
                $saved = $entityManager->getConnection()->transactional(function () use ($entityManager, $nameLock, $tags, &$tag, $name): bool {
                    $nameLock->lockNames($entityManager->getConnection(), [$name]);
                    $lockedTag = $tags->findForUpdate($tag->getId());
                    if (!$lockedTag instanceof ContentTag) {
                        throw new \RuntimeException('Das Schlagwort wurde während der Bearbeitung entfernt.');
                    }
                    $existing = $tags->findByNormalizedName($name);
                    if (null !== $existing && $existing->getId() !== $lockedTag->getId()) {
                        return false;
                    }

                    $tag = $lockedTag;
                    $tag->rename($name);
                    $entityManager->flush();

                    return true;
                });

                if ($saved) {
                    $this->addFlash('success', 'Schlagwort wurde gespeichert.');

                    return $this->redirectToRoute('admin_tag_index');
                }

                $errors[] = 'Dieses Schlagwort ist bereits vorhanden.';
            }

            $values['name'] = $name;
        }

        return $this->render('admin/tags/form.html.twig', [
            'values' => $values,
            'errors' => $errors,
            'is_edit' => true,
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
        ContentTagRepository $tags,
        EntityManagerInterface $entityManager,
        ContentTagNameLock $nameLock,
    ): Response {
        $tag = $tags->find($id);
        if (!$tag instanceof ContentTag) {
            throw $this->createNotFoundException('Schlagwort wurde nicht gefunden.');
        }

        $token = self::input($request->request, '_token');
        if ('' === $token || !$this->isCsrfTokenValid('admin_tag_delete_'.$tag->getId(), $token)) {
            throw $this->createAccessDeniedException();
        }

        if ($tags->countUsage($tag) > 0) {
            $this->addFlash('error', 'Das Schlagwort wird noch verwendet und kann nicht gelöscht werden.');

            return $this->redirectToRoute('admin_tag_index');
        }

        $deleted = $entityManager->getConnection()->transactional(function () use ($entityManager, $nameLock, $tags, $tag): bool {
            $nameLock->lockNames($entityManager->getConnection(), [$tag->getName()]);
            $lockedTag = $tags->findForUpdate($tag->getId());
            if (!$lockedTag instanceof ContentTag) {
                throw $this->createNotFoundException('Schlagwort wurde nicht gefunden.');
            }
            if ($tags->countUsage($lockedTag) > 0) {
                return false;
            }

            $entityManager->remove($lockedTag);
            $entityManager->flush();

            return true;
        });
        if (!$deleted) {
            $this->addFlash('error', 'Das Schlagwort wird noch verwendet und kann nicht gelöscht werden.');

            return $this->redirectToRoute('admin_tag_index');
        }

        $this->addFlash('success', 'Schlagwort wurde gelöscht.');

        return $this->redirectToRoute('admin_tag_index');
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

    private static function isValidName(string $name): bool
    {
        return '' !== $name
            && 1 === preg_match('//u', $name)
            && mb_strlen($name, 'UTF-8') <= 100
            && 1 !== preg_match('/[\x00-\x1F\x7F]/u', $name);
    }
}
