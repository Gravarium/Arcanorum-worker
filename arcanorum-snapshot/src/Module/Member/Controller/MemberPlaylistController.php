<?php

declare(strict_types=1);

namespace App\Module\Member\Controller;

use App\Entity\MemberAccount;
use App\Entity\Playlist;
use App\Repository\PlaylistRepository;
use App\Repository\VideoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/konto/wiedergabelisten', name: 'member_playlist_')]
final class MemberPlaylistController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        EntityManagerInterface $entityManager,
        PlaylistRepository $playlists,
    ): Response {
        $member = $this->currentMember();
        $values = ['name' => '', 'description' => '', 'visibility' => 'private'];
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('playlist_create', $request->request->getString('_token'))) {
                return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
            }

            $rawName = $request->request->getString('name');
            $rawDescription = $request->request->getString('description');
            $visibility = $request->request->getString('visibility');
            $values = [
                'name' => trim($rawName),
                'description' => trim($rawDescription),
                'visibility' => $visibility,
            ];

            if (strlen($rawName) > 320
                || strlen($rawDescription) > 2000
                || !in_array($visibility, ['private', 'public'], true)
            ) {
                $errors[] = 'Die Eingabe ist ungültig oder zu groß.';
            } else {
                try {
                    $playlist = new Playlist($member, $values['name'], $values['description'], 'public' === $visibility);
                    $entityManager->persist($playlist);
                    $entityManager->flush();
                    $this->addFlash('playlist_success', 'Die Wiedergabeliste wurde erstellt.');

                    return $this->noStore($this->redirectToRoute('member_playlist_index'));
                } catch (\InvalidArgumentException) {
                    $errors[] = 'Name oder Beschreibung enthält ungültige Angaben.';
                }
            }
        }

        $response = $this->render('member/playlists.html.twig', [
            'member' => $member,
            'playlists' => $playlists->findOwnedBy($member),
            'values' => $values,
            'errors' => $errors,
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));

        return $this->noStore($response);
    }

    #[Route('/video/{videoId}', name: 'add_video', requirements: ['videoId' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function addVideo(
        string $videoId,
        Request $request,
        EntityManagerInterface $entityManager,
        PlaylistRepository $playlists,
        VideoRepository $videos,
    ): Response {
        if (!$this->isCsrfTokenValid('playlist_add_'.$videoId, $request->request->getString('_token'))) {
            return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
        }

        $member = $this->currentMember();
        $playlistId = $request->request->getString('playlist_id');
        if (1 !== preg_match('/\A[a-f0-9]{32}\z/', $playlistId)) {
            $this->addFlash('playlist_error', 'Bitte wähle eine deiner Wiedergabelisten.');

            return $this->noStore($this->redirectToRoute('video_show', ['id' => $videoId]));
        }

        $playlist = $playlists->findOneBy(['id' => $playlistId, 'owner' => $member]);
        if (!$playlist instanceof Playlist) {
            throw $this->createNotFoundException();
        }

        $video = $videos->findPublishedById($videoId);
        if (null === $video) {
            throw $this->createNotFoundException();
        }

        try {
            $added = $playlist->addVideo($video);
            $entityManager->flush();
            $this->addFlash(
                'playlist_success',
                $added ? 'Das Video wurde gespeichert.' : 'Das Video ist bereits in dieser Wiedergabeliste.',
            );
        } catch (\InvalidArgumentException) {
            $this->addFlash('playlist_error', 'Das Video konnte nicht zur Wiedergabeliste hinzugefügt werden.');
        }

        return $this->noStore($this->redirectToRoute('video_show', ['id' => $videoId]));
    }

    #[Route('/{id}/bearbeiten', name: 'update', requirements: ['id' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function update(
        string $id,
        Request $request,
        EntityManagerInterface $entityManager,
        PlaylistRepository $playlists,
    ): Response {
        if (!$this->isCsrfTokenValid('playlist_update_'.$id, $request->request->getString('_token'))) {
            return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
        }

        $member = $this->currentMember();
        $playlist = $playlists->findOneBy(['id' => $id, 'owner' => $member]);
        if (!$playlist instanceof Playlist) {
            throw $this->createNotFoundException();
        }

        $name = $request->request->getString('name');
        $description = $request->request->getString('description');
        $visibility = $request->request->getString('visibility');
        if (strlen($name) > 320
            || strlen($description) > 2000
            || !in_array($visibility, ['private', 'public'], true)
        ) {
            $this->addFlash('playlist_error', 'Die Eingabe ist ungültig oder zu groß.');

            return $this->noStore($this->redirectToRoute('member_playlist_index', ['_fragment' => 'playlist-'.$id]));
        }

        try {
            $playlist->revise($name, $description, 'public' === $visibility);
            $entityManager->flush();
            $this->addFlash('playlist_success', 'Die Wiedergabeliste wurde aktualisiert.');
        } catch (\InvalidArgumentException) {
            $this->addFlash('playlist_error', 'Name oder Beschreibung enthält ungültige Angaben.');
        }

        return $this->noStore($this->redirectToRoute('member_playlist_index', ['_fragment' => 'playlist-'.$id]));
    }

    #[Route('/{id}/video/{videoId}/entfernen', name: 'remove_video', requirements: ['id' => '[a-f0-9]{32}', 'videoId' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function removeVideo(
        string $id,
        string $videoId,
        Request $request,
        EntityManagerInterface $entityManager,
        PlaylistRepository $playlists,
    ): Response {
        if (!$this->isCsrfTokenValid('playlist_remove_'.$id.'_'.$videoId, $request->request->getString('_token'))) {
            return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
        }

        $member = $this->currentMember();
        $playlist = $playlists->findOneBy(['id' => $id, 'owner' => $member]);
        if (!$playlist instanceof Playlist) {
            throw $this->createNotFoundException();
        }

        $playlist->removeVideoById($videoId);
        $entityManager->flush();
        $this->addFlash('playlist_success', 'Das Video wurde aus der Wiedergabeliste entfernt.');

        return $this->noStore($this->redirectToRoute('member_playlist_index', ['_fragment' => 'playlist-'.$id]));
    }

    #[Route('/{id}/loeschen', name: 'delete', requirements: ['id' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function delete(
        string $id,
        Request $request,
        EntityManagerInterface $entityManager,
        PlaylistRepository $playlists,
    ): Response {
        if (!$this->isCsrfTokenValid('playlist_delete_'.$id, $request->request->getString('_token'))) {
            return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
        }

        $member = $this->currentMember();
        $playlist = $playlists->findOneBy(['id' => $id, 'owner' => $member]);
        if (!$playlist instanceof Playlist) {
            throw $this->createNotFoundException();
        }

        $entityManager->remove($playlist);
        $entityManager->flush();
        $this->addFlash('playlist_success', 'Die Wiedergabeliste wurde gelöscht.');

        return $this->noStore($this->redirectToRoute('member_playlist_index'));
    }

    private function currentMember(): MemberAccount
    {
        $member = $this->getUser();
        if (!$member instanceof MemberAccount) {
            throw $this->createAccessDeniedException();
        }

        return $member;
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
