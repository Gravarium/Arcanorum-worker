<?php

declare(strict_types=1);

namespace App\Module\Contact\Controller;

use App\Entity\ContactMessage;
use App\Entity\ContactMessageStatus;
use App\Repository\ContactMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/contact-messages', name: 'admin_contact_message_')]
#[IsGranted('ROLE_ADMIN')]
final class ContactAdminController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, ContactMessageRepository $messages): Response
    {
        $search = mb_substr(trim($request->query->getString('q')), 0, 100, 'UTF-8');
        $status = ContactMessageStatus::tryFrom($request->query->getString('status'));

        return $this->render('admin/contact_messages/index.html.twig', [
            'messages' => $messages->findForAdmin($status, $search),
            'search' => $search,
            'selected_status' => $status->value ?? '',
            'statuses' => ContactMessageStatus::cases(),
        ]);
    }

    #[Route(
        '/{id}/close',
        name: 'close',
        requirements: ['id' => '[a-f0-9]{32}'],
        methods: ['POST'],
    )]
    public function close(
        string $id,
        Request $request,
        ContactMessageRepository $messages,
        EntityManagerInterface $entityManager,
    ): Response {
        $message = $messages->find($id);
        if (!$message instanceof ContactMessage) {
            throw $this->createNotFoundException('Kontaktnachricht wurde nicht gefunden.');
        }
        if (!$this->isCsrfTokenValid('admin_contact_message_close_'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (ContactMessageStatus::Pending !== $message->getStatus()) {
            $this->addFlash('error', 'Diese Kontaktnachricht wurde bereits abgeschlossen.');

            return $this->redirectToRoute('admin_contact_message_index');
        }

        $message->resolve();
        $entityManager->flush();
        $this->addFlash('success', 'Kontaktnachricht wurde abgeschlossen.');

        return $this->redirectToRoute('admin_contact_message_index');
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
        ContactMessageRepository $messages,
        EntityManagerInterface $entityManager,
    ): Response {
        $message = $messages->find($id);
        if (!$message instanceof ContactMessage) {
            throw $this->createNotFoundException('Kontaktnachricht wurde nicht gefunden.');
        }
        if (!$this->isCsrfTokenValid('admin_contact_message_delete_'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (ContactMessageStatus::Resolved !== $message->getStatus()) {
            $this->addFlash('error', 'Offene Kontaktnachrichten müssen zuerst abgeschlossen werden.');

            return $this->redirectToRoute('admin_contact_message_index');
        }

        $entityManager->remove($message);
        $entityManager->flush();
        $this->addFlash('success', 'Kontaktnachricht wurde gelöscht.');

        return $this->redirectToRoute('admin_contact_message_index');
    }
}
