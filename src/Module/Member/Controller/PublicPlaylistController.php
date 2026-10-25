<?php

declare(strict_types=1);

namespace App\Module\Member\Controller;

use App\Entity\Playlist;
use App\Repository\PlaylistItemRepository;
use App\Repository\PlaylistRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/wiedergabelisten', name: 'public_playlist_')]
final class PublicPlaylistController extends AbstractController
{
    #[Route('/{id}', name: 'show', requirements: ['id' => '[a-f0-9]{32}'], methods: ['GET'])]
    public function show(
        string $id,
        PlaylistRepository $playlists,
        PlaylistItemRepository $items,
    ): Response {
        $playlist = $playlists->findVisibleById($id);
        if (!$playlist instanceof Playlist) {
            return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store, private']);
        }

        $response = $this->render('playlists/show.html.twig', [
            'playlist' => $playlist,
            'items' => $items->findPublishedForPlaylist($playlist),
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
