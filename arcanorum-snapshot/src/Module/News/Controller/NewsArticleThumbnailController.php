<?php

declare(strict_types=1);

namespace App\Module\News\Controller;

use App\Entity\NewsArticle;
use App\Module\News\Service\NewsArticleAccessPolicy;
use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use App\Repository\NewsArticleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class NewsArticleThumbnailController extends AbstractController
{
    private const int MAX_FILE_BYTES = 4194304;
    private const int MAX_DIMENSION = 4096;

    /** @var list<string> */
    private const array ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private readonly PrivateObjectStorageInterface $storage)
    {
    }

    #[Route('/news/{id}/thumbnail', name: 'news_thumbnail', requirements: ['id' => '[a-f0-9]{32}'], methods: ['GET'])]
    public function show(string $id, NewsArticleRepository $articles, NewsArticleAccessPolicy $access): Response
    {
        $article = $articles->findPublishedById($id);
        if (!$article instanceof NewsArticle || !$access->canRead($article, $this->getUser())) {
            return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
        }

        return $this->imageResponse($article, $article->isMembersOnly());
    }

    #[Route('/admin/news/{id}/thumbnail', name: 'admin_news_thumbnail_preview', requirements: ['id' => '[a-f0-9]{32}'], methods: ['GET'])]
    #[IsGranted('ROLE_CONTENT_EDITOR')]
    public function preview(string $id, NewsArticleRepository $articles): Response
    {
        $article = $articles->find($id);
        if (!$article instanceof NewsArticle) {
            return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
        }

        return $this->imageResponse($article, true);
    }

    private function imageResponse(NewsArticle $article, bool $private): Response
    {
        $objectId = $article->getThumbnailObjectId();
        $expectedMimeType = $article->getThumbnailMimeType();
        $expectedSize = $article->getThumbnailSize();
        if (null === $objectId
            || 1 !== preg_match('/\A[a-f0-9]{64}\z/', $objectId)
            || null === $expectedMimeType
            || null === $expectedSize
            || !in_array($expectedMimeType, self::ALLOWED_MIME_TYPES, true)
            || $expectedSize < 1
            || $expectedSize > self::MAX_FILE_BYTES
        ) {
            return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
        }

        try {
            $contents = $this->storage->read($objectId, $expectedSize);
        } catch (PrivateStorageOperationFailed) {
            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE, ['Cache-Control' => 'no-store']);
        }

        $detectedMimeType = new \finfo(FILEINFO_MIME_TYPE)->buffer($contents);
        $imageInfo = @getimagesizefromstring($contents);
        if (strlen($contents) !== $expectedSize
            || !is_string($detectedMimeType)
            || $detectedMimeType !== $expectedMimeType
            || !in_array($detectedMimeType, self::ALLOWED_MIME_TYPES, true)
            || false === $imageInfo
            || $detectedMimeType !== $imageInfo['mime']
            || $imageInfo[0] < 1
            || $imageInfo[1] < 1
            || $imageInfo[0] > self::MAX_DIMENSION
            || $imageInfo[1] > self::MAX_DIMENSION
        ) {
            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE, ['Cache-Control' => 'no-store']);
        }

        return new Response($contents, Response::HTTP_OK, [
            'Content-Type' => $detectedMimeType,
            'Content-Length' => (string) $expectedSize,
            'Cache-Control' => $private ? 'private, no-store' : 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }
}
