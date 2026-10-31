<?php

declare(strict_types=1);

namespace App\Module\Content\Controller;

use App\Entity\ContentCategory;
use App\Entity\ContentCategoryType;
use App\Entity\ContentInlineImage;
use App\Module\Settings\Service\ModuleAvailability;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use App\Repository\ContentCategoryRepository;
use App\Repository\ContentInlineImageRepository;
use App\Service\ContentInlineImageManager;
use App\Service\ContentInlineImageReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/content-inline-images', name: 'admin_content_inline_image_')]
#[IsGranted('ROLE_CONTENT_EDITOR')]
final class AdminContentInlineImageController extends AbstractController
{
    #[Route(
        '/{type}/{ownerId}',
        name: 'upload',
        requirements: ['type' => 'news|page|news-category|video-category', 'ownerId' => '[a-f0-9]{32}'],
        methods: ['POST'],
    )]
    public function upload(
        string $type,
        string $ownerId,
        Request $request,
        ContentInlineImageManager $images,
        ModuleAvailability $modules,
    ): JsonResponse {
        $moduleId = [
            'news' => 'news',
            'page' => 'pages',
            'news-category' => 'news',
            'video-category' => 'videos',
        ][$type] ?? null;
        if (null === $moduleId || !$modules->isEnabled($moduleId)) {
            return $this->failure('Der angeforderte Inhalt ist nicht verfügbar.', Response::HTTP_NOT_FOUND);
        }

        $expectedTokenId = 'admin_inline_image_upload_'.$type.'_'.$ownerId;
        $token = self::input($request->request, '_token');
        if ('' === $token || !$this->isCsrfTokenValid($expectedTokenId, $token)) {
            throw $this->createAccessDeniedException();
        }

        if ([] !== array_diff(array_keys($request->request->all()), ['_token'])
            || [] !== array_diff(array_keys($request->files->all()), ['image'])
        ) {
            return $this->failure('Die Anfrage enthält unerwartete Eingaben.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $upload = $request->files->get('image');
        if (!$upload instanceof UploadedFile) {
            return $this->failure('Bitte wähle eine einzelne gültige Bilddatei aus.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $image = match ($type) {
                'news' => $images->uploadForNewsArticle($ownerId, $upload),
                'page' => $images->uploadForStaticPage($ownerId, $upload),
                'news-category' => $images->uploadForCategory($ownerId, ContentCategoryType::News, $upload),
                'video-category' => $images->uploadForCategory($ownerId, ContentCategoryType::Video, $upload),
                default => throw $this->createNotFoundException(),
            };
        } catch (\InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (PrivateStorageOperationFailed) {
            return $this->failure('Der private Bildspeicher ist derzeit nicht verfügbar.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $result = ['url' => $this->generateUrl('content_inline_image_show', ['id' => $image->getId()])];
        if (in_array($type, ['news-category', 'video-category'], true)) {
            $result['preview_url'] = $this->generateUrl('admin_content_inline_image_preview', [
                'type' => $type,
                'ownerId' => $ownerId,
                'imageId' => $image->getId(),
            ]);
        }
        $response = new JsonResponse($result);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    #[Route(
        '/{type}/{ownerId}/{imageId}',
        name: 'preview',
        requirements: [
            'type' => 'news-category|video-category',
            'ownerId' => '[a-f0-9]{32}',
            'imageId' => '[a-f0-9]{64}',
        ],
        methods: ['GET'],
    )]
    public function preview(
        string $type,
        string $ownerId,
        string $imageId,
        ContentCategoryRepository $categories,
        ContentInlineImageRepository $images,
        ContentInlineImageReader $reader,
        ModuleAvailability $modules,
    ): Response {
        $contentType = match ($type) {
            'news-category' => ContentCategoryType::News,
            'video-category' => ContentCategoryType::Video,
            default => null,
        };
        if (!$contentType instanceof ContentCategoryType
            || (ContentCategoryType::Video === $contentType && !$this->isGranted('ROLE_ADMIN'))
        ) {
            throw $this->createAccessDeniedException();
        }

        $moduleId = ContentCategoryType::News === $contentType ? 'news' : 'videos';
        if (!$modules->isEnabled($moduleId)) {
            return $this->notFound();
        }

        $category = $categories->find($ownerId);
        $image = $images->find($imageId);
        if (!$category instanceof ContentCategory
            || $category->getContentType() !== $contentType
            || !$image instanceof ContentInlineImage
            || $image->getCategory()?->getId() !== $category->getId()
        ) {
            return $this->notFound();
        }

        return $reader->response($image, true);
    }

    private function failure(string $message, int $status): JsonResponse
    {
        $response = new JsonResponse(['error' => $message], $status);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function notFound(): Response
    {
        return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
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
