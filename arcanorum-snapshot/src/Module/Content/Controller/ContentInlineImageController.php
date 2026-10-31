<?php

declare(strict_types=1);

namespace App\Module\Content\Controller;

use App\Entity\ContentCategory;
use App\Entity\ContentCategoryType;
use App\Entity\ContentInlineImage;
use App\Entity\NewsArticle;
use App\Entity\StaticPage;
use App\Module\News\Service\NewsArticleAccessPolicy;
use App\Module\Settings\Service\ModuleAvailability;
use App\Repository\ContentInlineImageRepository;
use App\Repository\NewsArticleRepository;
use App\Repository\StaticPageRepository;
use App\Service\ContentInlineImageReader;
use App\Service\RichTextSanitizer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/content-inline-images', name: 'content_inline_image_')]
final class ContentInlineImageController extends AbstractController
{
    #[Route('/{id}', name: 'show', requirements: ['id' => '[a-f0-9]{64}'], methods: ['GET'])]
    public function show(
        string $id,
        ContentInlineImageRepository $images,
        NewsArticleRepository $articles,
        StaticPageRepository $pages,
        NewsArticleAccessPolicy $newsAccess,
        RichTextSanitizer $richText,
        ModuleAvailability $modules,
        ContentInlineImageReader $reader,
    ): Response {
        $image = $images->find($id);
        if (!$image instanceof ContentInlineImage) {
            return $this->notFound();
        }

        $private = true;
        $article = $image->getNewsArticle();
        $page = $image->getStaticPage();
        $category = $image->getCategory();

        if ($article instanceof NewsArticle) {
            if (!$modules->isEnabled('news')) {
                return $this->notFound();
            }

            if (!$this->isGranted('ROLE_CONTENT_EDITOR')) {
                $published = $articles->findPublishedById($article->getId());
                if (!$published instanceof NewsArticle
                    || !$newsAccess->canRead($published, $this->getUser())
                    || !in_array($id, $richText->inlineImageIds($published->getBody()), true)
                ) {
                    return $this->notFound();
                }
                $private = $published->isMembersOnly();
            }
        } elseif ($page instanceof StaticPage) {
            if (!$modules->isEnabled('pages')) {
                return $this->notFound();
            }

            if (!$this->isGranted('ROLE_ADMIN')) {
                $published = $pages->findPublishedBySlug($page->getSlug());
                if (!$published instanceof StaticPage
                    || $published->getId() !== $page->getId()
                    || !in_array($id, $richText->inlineImageIds($published->getBody()), true)
                ) {
                    return $this->notFound();
                }
                $private = false;
            }
        } elseif ($category instanceof ContentCategory) {
            $moduleId = ContentCategoryType::News === $category->getContentType() ? 'news' : 'videos';
            if (!$modules->isEnabled($moduleId)) {
                return $this->notFound();
            }

            if (!in_array($id, $richText->inlineImageIds($category->getDescription()), true)) {
                return $this->notFound();
            }
            $private = false;
        } else {
            return $this->notFound();
        }

        return $reader->response($image, $private);
    }

    private function notFound(): Response
    {
        return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
    }
}
