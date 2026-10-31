<?php

declare(strict_types=1);

namespace App\Module\Comment\Controller;

use App\Module\Comment\Service\EmojiCatalog;
use App\Module\Settings\Service\ModuleAvailability;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicEmojiHelpController extends AbstractController
{
    #[Route('/hilfe/emoji', name: 'public_emoji_help', methods: ['GET'])]
    public function index(EmojiCatalog $catalog, ModuleAvailability $modules): Response
    {
        if (!$modules->isEnabled('comments')) {
            throw $this->createNotFoundException();
        }

        return $this->render('comments/emoji_help.html.twig', [
            'categories' => $catalog->categories(),
        ]);
    }

    #[Route('/hilfe/emoji/katalog.json', name: 'public_emoji_catalog', methods: ['GET'])]
    public function catalog(EmojiCatalog $catalog, ModuleAvailability $modules): JsonResponse
    {
        if (!$modules->isEnabled('comments')) {
            throw $this->createNotFoundException();
        }

        $response = new JsonResponse(['categories' => $catalog->publicCategories()]);
        $response->setPublic();
        $response->setMaxAge(86400);

        return $response;
    }
}
