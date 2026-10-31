<?php

declare(strict_types=1);

namespace App\Module\Comment\Controller;

use App\Entity\CommentFilterAction;
use App\Entity\CommentFilterTerm;
use App\Repository\CommentFilterTermRepository;
use App\Service\CommentWordFilterNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/comments/word-filter', name: 'admin_comment_filter_')]
#[IsGranted('ROLE_ADMIN')]
final class CommentWordFilterAdminController extends AbstractController
{
    private const int MAX_TERMS = 500;

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(CommentFilterTermRepository $terms): Response
    {
        return $this->renderIndex($terms, [], '', CommentFilterAction::Review->value);
    }

    #[Route('', name: 'save', methods: ['POST'])]
    public function save(
        Request $request,
        CommentFilterTermRepository $terms,
        EntityManagerInterface $entityManager,
    ): Response {
        $token = self::input($request->request, '_token');
        if ('' === $token || !$this->isCsrfTokenValid('admin_comment_filter_save', $token)) {
            throw $this->createAccessDeniedException();
        }

        $termValue = self::input($request->request, 'term');
        $submittedFields = $request->request->all();
        $submittedAction = $submittedFields['action'] ?? CommentFilterAction::Review->value;
        $actionValue = is_string($submittedAction) ? $submittedAction : '';
        $action = CommentFilterAction::tryFrom($actionValue);
        $errors = [];
        if (null === $action) {
            $errors[] = 'Bitte wähle eine gültige Filteraktion.';
        }
        try {
            $parsed = CommentWordFilterNormalizer::parseTerm($termValue);
        } catch (\InvalidArgumentException $exception) {
            $parsed = null;
            $errors[] = $exception->getMessage();
        }

        if (null !== $parsed && $terms->countForAdmin() >= self::MAX_TERMS) {
            $errors[] = 'Die Liste ist auf 500 Filterwörter oder Wortgruppen begrenzt.';
        }
        if (null !== $parsed
            && null !== $action
            && null !== $terms->findByNormalizedTermAndAction($parsed['normalized'], $action)
        ) {
            $errors[] = 'Dieses Filterwort oder diese Wortgruppe ist bereits mit dieser Aktion vorhanden.';
        }

        if ([] === $errors && null !== $action) {
            $entityManager->persist(new CommentFilterTerm($parsed['term'], $parsed['normalized'], $action));
            $entityManager->flush();
            $this->addFlash('success', 'Filterwort wurde hinzugefügt.');

            return $this->redirectToRoute('admin_comment_filter_index');
        }

        return $this->renderIndex($terms, $errors, $termValue, $actionValue, Response::HTTP_UNPROCESSABLE_ENTITY);
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
        CommentFilterTermRepository $terms,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
    ): Response {
        $term = $terms->find($id);
        if (!$term instanceof CommentFilterTerm) {
            throw $this->createNotFoundException($translator->trans('Filterwort wurde nicht gefunden.'));
        }

        $token = self::input($request->request, '_token');
        if ('' === $token || !$this->isCsrfTokenValid('admin_comment_filter_delete_'.$term->getId(), $token)) {
            throw $this->createAccessDeniedException();
        }

        $entityManager->remove($term);
        $entityManager->flush();
        $this->addFlash('success', 'Filterwort wurde entfernt.');

        return $this->redirectToRoute('admin_comment_filter_index');
    }

    /**
     * @param list<string> $errors
     */
    private function renderIndex(
        CommentFilterTermRepository $terms,
        array $errors,
        string $termValue,
        string $actionValue,
        int $status = Response::HTTP_OK,
    ): Response {
        return $this->render('admin/comments/word_filter.html.twig', [
            'terms' => $terms->findForAdmin(),
            'term_count' => $terms->countForAdmin(),
            'max_terms' => self::MAX_TERMS,
            'errors' => $errors,
            'term_value' => $termValue,
            'action_value' => $actionValue,
        ], new Response(status: $status));
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
