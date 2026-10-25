<?php

declare(strict_types=1);

namespace App\Module\Comment\Controller;

use App\Entity\CommentContentType;
use App\Entity\CommentStatus;
use App\Entity\ContentComment;
use App\Entity\MemberAccount;
use App\Entity\NewsArticle;
use App\Entity\Video;
use App\Repository\ContentCommentRepository;
use App\Repository\NewsArticleRepository;
use App\Repository\VideoRepository;
use App\Service\CommentWordFilter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/comments', name: 'public_comment_')]
final class PublicCommentController extends AbstractController
{
    #[Route(
        '/{contentType}/{reference}',
        name: 'submit',
        requirements: [
            'contentType' => 'news|video',
            'reference' => '(?:[a-f0-9]{32}|[a-z0-9]+(?:-[a-z0-9]+)*)',
        ],
        methods: ['POST'],
    )]
    public function submit(
        string $contentType,
        string $reference,
        Request $request,
        NewsArticleRepository $articles,
        VideoRepository $videos,
        CommentWordFilter $wordFilter,
        EntityManagerInterface $entityManager,
    ): Response {
        $type = CommentContentType::tryFrom($contentType);
        if (null === $type) {
            return $this->notFound();
        }

        $target = match ($type) {
            CommentContentType::News => $articles->findPublishedBySlug($reference),
            CommentContentType::Video => $videos->findPublishedById($reference),
        };
        if (!$target instanceof NewsArticle && !$target instanceof Video) {
            return $this->notFound();
        }

        $route = $target instanceof NewsArticle ? 'news_show' : 'video_show';
        $routeParameters = $target instanceof NewsArticle
            ? ['slug' => $target->getSlug()]
            : ['id' => $target->getId()];
        $routeParameters['_fragment'] = 'comments';

        if (!$target->isCommentsEnabled()) {
            $this->addFlash('public_comment_error', 'Kommentare sind für diesen Inhalt deaktiviert.');

            return $this->redirectToRoute($route, $routeParameters);
        }

        $csrfId = 'public_comment_'.$type->value.'_'.$target->getId();
        if (!$this->isCsrfTokenValid($csrfId, $request->request->getString('_token'))) {
            return new Response(status: Response::HTTP_FORBIDDEN);
        }

        if ('' !== trim($request->request->getString('website'))) {
            return $this->redirectToRoute($route, $routeParameters);
        }

        $session = $request->getSession();
        $previousSubmission = $session->get('public_comment_last_submission');
        if (is_int($previousSubmission) && time() - $previousSubmission < 15) {
            $this->addFlash('public_comment_error', 'Bitte warte kurz, bevor du einen weiteren Kommentar sendest.');

            return $this->redirectToRoute($route, $routeParameters);
        }

        $authorName = trim($request->request->getString('author_name'));
        $body = trim($request->request->getString('body'));
        $errors = [];
        if (1 !== preg_match('//u', $authorName)
            || 1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $authorName)
        ) {
            $errors[] = 'Der Name muss gültiges UTF-8 ohne unzulässige Steuerzeichen enthalten.';
        } elseif ('' === $authorName || mb_strlen($authorName, 'UTF-8') > 80) {
            $errors[] = 'Der Name muss zwischen 1 und 80 Zeichen lang sein.';
        }
        if (1 !== preg_match('//u', $body)
            || 1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $body)
        ) {
            $errors[] = 'Der Kommentar muss gültiges UTF-8 ohne unzulässige Steuerzeichen enthalten.';
        } elseif (mb_strlen($body, 'UTF-8') < 5 || mb_strlen($body, 'UTF-8') > 3000) {
            $errors[] = 'Der Kommentar muss zwischen 5 und 3000 Zeichen lang sein.';
        }
        foreach ($errors as $error) {
            $this->addFlash('public_comment_error', $error);
        }
        if ([] !== $errors) {
            return $this->redirectToRoute($route, $routeParameters);
        }

        $comment = new ContentComment($type, $target->getId(), $target->getTitle(), $authorName, $body);
        $member = $this->getUser();
        if ($member instanceof MemberAccount) {
            $comment->attributeToMember($member);
        }
        $matchedFilterTerm = $wordFilter->findMatchingTerm($body);
        if (null !== $matchedFilterTerm) {
            $comment->markForWordFilterReview($matchedFilterTerm);
        }
        $entityManager->persist($comment);
        $entityManager->flush();
        $session->set('public_comment_last_submission', time());
        $this->addFlash('public_comment_success', 'Dein Kommentar wurde zur Prüfung eingereicht.');

        return $this->redirectToRoute($route, $routeParameters);
    }

    #[Route(
        '/{id}/report',
        name: 'report',
        requirements: ['id' => '[a-f0-9]{32}'],
        methods: ['POST'],
    )]
    public function report(
        string $id,
        Request $request,
        ContentCommentRepository $comments,
        NewsArticleRepository $articles,
        VideoRepository $videos,
        EntityManagerInterface $entityManager,
    ): Response {
        $comment = $comments->find($id);
        if (!$comment instanceof ContentComment || CommentStatus::Approved !== $comment->getStatus()) {
            return $this->notFound();
        }

        if (!$this->isCsrfTokenValid('public_comment_report_'.$comment->getId(), $request->request->getString('_token'))) {
            return new Response('', Response::HTTP_FORBIDDEN, ['Cache-Control' => 'no-store']);
        }

        $session = $request->getSession();
        $previousReport = $session->get('public_comment_last_report');
        if (is_int($previousReport) && time() - $previousReport < 15) {
            $this->addFlash('public_comment_error', 'Bitte warte kurz, bevor du einen weiteren Kommentar meldest.');

            return $this->redirectToRoute(
                CommentContentType::News === $comment->getContentType() ? 'news_index' : 'video_index',
            );
        }

        $comment->report();
        $entityManager->flush();
        $session->set('public_comment_last_report', time());
        $this->addFlash('public_comment_success', 'Danke. Der Kommentar wurde zur Prüfung gemeldet.');

        if (CommentContentType::News === $comment->getContentType()) {
            $article = $articles->findPublishedById($comment->getContentId());
            if (!$article instanceof NewsArticle) {
                return $this->redirectToRoute('news_index');
            }

            return $this->redirectToRoute('news_show', ['slug' => $article->getSlug(), '_fragment' => 'comments']);
        }

        $video = $videos->findPublishedById($comment->getContentId());
        if (!$video instanceof Video) {
            return $this->redirectToRoute('video_index');
        }

        return $this->redirectToRoute('video_show', ['id' => $video->getId(), '_fragment' => 'comments']);
    }

    private function notFound(): Response
    {
        return $this->render('public/not_found.html.twig', [
            'content_type' => 'Kommentar',
        ], new Response(status: Response::HTTP_NOT_FOUND));
    }
}
