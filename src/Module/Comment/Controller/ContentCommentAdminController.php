<?php

declare(strict_types=1);

namespace App\Module\Comment\Controller;

use App\Entity\CommentStatus;
use App\Entity\ContentComment;
use App\Repository\ContentCommentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/comments', name: 'admin_comment_')]
#[IsGranted('ROLE_MODERATOR_COMMENTS')]
final class ContentCommentAdminController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, ContentCommentRepository $comments): Response
    {
        $search = mb_substr(trim($request->query->getString('q')), 0, 100);
        $status = CommentStatus::tryFrom($request->query->getString('status'));

        return $this->render('admin/comments/index.html.twig', [
            'comments' => $comments->findForModeration($search, $status),
            'search' => $search,
            'selected_status' => $status?->value,
            'statuses' => CommentStatus::cases(),
        ]);
    }

    #[Route(
        '/{id}/moderate',
        name: 'moderate',
        requirements: ['id' => '[a-f0-9]{32}'],
        methods: ['POST'],
    )]
    public function moderate(
        string $id,
        Request $request,
        ContentCommentRepository $comments,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
    ): Response {
        $comment = $comments->find($id);
        if (!$comment instanceof ContentComment) {
            throw $this->createNotFoundException($translator->trans('Kommentar wurde nicht gefunden.'));
        }
        if (!$this->isCsrfTokenValid('admin_comment_action_'.$comment->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $action = $request->request->getString('action');
        if ('approve' === $action) {
            $comment->approve();
            $this->addFlash('success', 'Kommentar wurde freigegeben.');
        } elseif ('reject' === $action) {
            $comment->reject();
            $this->addFlash('success', 'Kommentar wurde abgelehnt.');
        } elseif ('clear_reports' === $action) {
            $comment->clearReports();
            $this->addFlash('success', 'Meldungen wurden zurückgesetzt.');
        } elseif ('delete' === $action) {
            $entityManager->remove($comment);
            $this->addFlash('success', 'Kommentar wurde gelöscht.');
        } else {
            $this->addFlash('error', 'Die gewählte Aktion ist ungültig.');

            return $this->redirectToRoute('admin_comment_index');
        }

        $entityManager->flush();

        return $this->redirectToRoute('admin_comment_index');
    }
}
