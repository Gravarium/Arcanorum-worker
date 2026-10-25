<?php

declare(strict_types=1);

namespace App\Module\Backup\Controller;

use App\Module\Backup\Service\DatabaseBackupService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/backups', name: 'admin_database_backup_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminDatabaseBackupController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(DatabaseBackupService $backups): Response
    {
        $availability = $backups->availability();
        try {
            $entries = $availability['available'] ? $backups->list() : [];
        } catch (\Throwable) {
            $availability = ['available' => false, 'reason' => 'Sicherungsdaten konnten nicht sicher gelesen werden.'];
            $entries = [];
        }

        return $this->noStore($this->render('admin/backups/index.html.twig', [
            'entries' => $entries,
            'availability' => $availability,
        ]));
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request, DatabaseBackupService $backups, TranslatorInterface $translator): Response
    {
        if (!$this->validPost($request, 'admin_database_backup_create')) {
            return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
        }

        try {
            $backup = $backups->create();
            $this->addFlash('success', $translator->trans('Datenbanksicherung erstellt (%created_at%, %size_bytes% Bytes).', [
                '%created_at%' => $backup['created_at'],
                '%size_bytes%' => $backup['size_bytes'],
            ]));
        } catch (\Throwable) {
            return $this->operationFailed($translator);
        }

        return $this->noStore($this->redirectToRoute('admin_database_backup_index'));
    }

    #[Route('/{id}/verify', name: 'verify', requirements: ['id' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function verify(string $id, Request $request, DatabaseBackupService $backups, TranslatorInterface $translator): Response
    {
        if (!$this->validPost($request, 'admin_database_backup_verify_'.$id)) {
            return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
        }

        try {
            $backups->verify($id);
            $this->addFlash('success', $translator->trans('Die Sicherung wurde entschlüsselt, authentifiziert und als PostgreSQL-Archiv geprüft.'));
        } catch (\Throwable) {
            return $this->operationFailed($translator);
        }

        return $this->noStore($this->redirectToRoute('admin_database_backup_index'));
    }

    #[Route('/{id}/download', name: 'download', requirements: ['id' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function download(string $id, Request $request, DatabaseBackupService $backups, TranslatorInterface $translator): Response
    {
        if (!$this->validPost($request, 'admin_database_backup_download_'.$id)) {
            return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
        }

        try {
            $archivePath = $backups->decryptedArchivePath($id);
        } catch (\Throwable) {
            return $this->noStore(new Response($translator->trans('Sicherung nicht verfügbar.'), Response::HTTP_NOT_FOUND));
        }

        $response = new StreamedResponse(static function () use ($archivePath): void {
            $handle = @fopen($archivePath, 'rb');
            if (false === $handle) {
                @unlink($archivePath);

                return;
            }
            try {
                fpassthru($handle);
            } finally {
                fclose($handle);
                @unlink($archivePath);
            }
        });
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            'arcanorum-database-backup-'.$id.'.dump',
        ));
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function delete(string $id, Request $request, DatabaseBackupService $backups, TranslatorInterface $translator): Response
    {
        if (!$this->validPost($request, 'admin_database_backup_delete_'.$id)) {
            return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
        }

        try {
            $backups->delete($id);
            $this->addFlash('success', $translator->trans('Die ausgewählte Sicherung wurde gelöscht.'));
        } catch (\Throwable) {
            return $this->operationFailed($translator);
        }

        return $this->noStore($this->redirectToRoute('admin_database_backup_index'));
    }

    private function validPost(Request $request, string $csrfId): bool
    {
        $submittedFields = array_keys($request->request->all());
        if ([] !== array_diff($submittedFields, ['_token']) || [] !== array_diff(['_token'], $submittedFields)) {
            return false;
        }

        return $this->isCsrfTokenValid($csrfId, $request->request->getString('_token'));
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    private function operationFailed(TranslatorInterface $translator): Response
    {
        return $this->noStore(new Response(
            $translator->trans('Die Sicherungsaktion konnte nicht sicher ausgeführt werden.'),
            Response::HTTP_SERVICE_UNAVAILABLE,
        ));
    }
}
