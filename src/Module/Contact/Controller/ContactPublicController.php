<?php

declare(strict_types=1);

namespace App\Module\Contact\Controller;

use App\Entity\ContactMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/contact', name: 'contact_')]
final class ContactPublicController extends AbstractController
{
    private const int COOLDOWN_SECONDS = 60;

    #[Route('', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $values = [
            'name' => '',
            'email' => '',
            'subject' => '',
            'body' => '',
        ];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('public_contact', $request->request->getString('_token'))) {
                return new Response('', Response::HTTP_FORBIDDEN, ['Cache-Control' => 'no-store']);
            }

            if ('' !== trim($request->request->getString('website'))) {
                $response = $this->redirectToRoute('contact_new');
                $response->headers->set('Cache-Control', 'no-store, private');

                return $response;
            }

            $session = $request->getSession();
            $lastSentAt = $session->get('public_contact_last_sent_at');
            if (is_int($lastSentAt) && time() - $lastSentAt < self::COOLDOWN_SECONDS) {
                $this->addFlash('contact_error', 'Bitte warte kurz, bevor du eine weitere Nachricht sendest.');

                return $this->renderContactForm($values, Response::HTTP_TOO_MANY_REQUESTS);
            }

            $rawValues = [
                'name' => $request->request->getString('name'),
                'email' => $request->request->getString('email'),
                'subject' => $request->request->getString('subject'),
                'body' => $request->request->getString('body'),
            ];
            $values = [
                'name' => trim($rawValues['name']),
                'email' => trim($rawValues['email']),
                'subject' => trim($rawValues['subject']),
                'body' => trim($rawValues['body']),
            ];

            $errors = [];
            if (strlen($rawValues['name']) > 480
                || strlen($rawValues['email']) > 1016
                || strlen($rawValues['subject']) > 640
                || strlen($rawValues['body']) > 20000
            ) {
                $errors[] = 'Die Eingabe ist zu groß. Bitte kürze deine Nachricht und versuche es erneut.';
            } else {
                if (!$this->validText($values['name'])
                    || '' === $values['name']
                    || mb_strlen($values['name'], 'UTF-8') > 120
                ) {
                    $errors[] = 'Der Name muss 1 bis 120 Zeichen lang sein und gültigen Text enthalten.';
                }
                if (!$this->validText($values['email'])
                    || mb_strlen($values['email'], 'UTF-8') > 254
                    || false === filter_var($values['email'], FILTER_VALIDATE_EMAIL)
                ) {
                    $errors[] = 'Bitte gib eine gültige E-Mail-Adresse ein.';
                }
                if (!$this->validText($values['subject'])
                    || '' === $values['subject']
                    || mb_strlen($values['subject'], 'UTF-8') > 160
                ) {
                    $errors[] = 'Der Betreff muss 1 bis 160 Zeichen lang sein und gültigen Text enthalten.';
                }
                if (!$this->validText($values['body'])
                    || '' === $values['body']
                    || mb_strlen($values['body'], 'UTF-8') > 5000
                ) {
                    $errors[] = 'Die Nachricht muss 1 bis 5.000 Zeichen lang sein und gültigen Text enthalten.';
                }
            }

            if ([] !== $errors) {
                foreach ($errors as $error) {
                    $this->addFlash('contact_error', $error);
                }

                return $this->renderContactForm($values, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $message = new ContactMessage(
                $values['name'],
                $values['email'],
                $values['subject'],
                $values['body'],
            );
            $entityManager->persist($message);
            $entityManager->flush();
            $session->set('public_contact_last_sent_at', time());
            $this->addFlash('contact_success', 'Danke. Deine Nachricht wurde zur Bearbeitung gespeichert.');

            $response = $this->redirectToRoute('contact_new');
            $response->headers->set('Cache-Control', 'no-store, private');

            return $response;
        }

        return $this->renderContactForm($values);
    }

    /**
     * @param array{name: string, email: string, subject: string, body: string} $values
     */
    private function renderContactForm(array $values, int $status = Response::HTTP_OK): Response
    {
        $response = $this->render('contact/index.html.twig', [
            'values' => $values,
        ], new Response(status: $status));
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function validText(string $value): bool
    {
        return 1 === preg_match('//u', $value)
            && 1 !== preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value);
    }
}
