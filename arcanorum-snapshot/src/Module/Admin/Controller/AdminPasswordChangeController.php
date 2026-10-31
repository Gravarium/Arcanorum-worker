<?php

declare(strict_types=1);

namespace App\Module\Admin\Controller;

use App\Entity\AdminActivityEntry;
use App\Entity\AdminUser;
use App\Platform\Security\RateLimitKeyFactory;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/password', name: 'admin_password_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminPasswordChangeController extends AbstractController
{
    private const int MINIMUM_PASSWORD_CHARACTERS = 16;
    private const int MAXIMUM_PASSWORD_BYTES = 4096;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly RateLimitKeyFactory $rateLimitKeys,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.admin_password_change')]
        private readonly RateLimiterFactoryInterface $passwordChangeLimiter,
    ) {
    }

    #[Route('', name: 'change', methods: ['GET', 'POST'])]
    public function change(Request $request, TranslatorInterface $translator): Response
    {
        $administrator = $this->getUser();
        if (!$administrator instanceof AdminUser) {
            throw $this->createAccessDeniedException();
        }

        $errors = [];
        $status = Response::HTTP_OK;
        if ($request->isMethod(Request::METHOD_POST)) {
            $submitted = $request->request->all();
            $csrfToken = $submitted['_token'] ?? null;
            if (!is_string($csrfToken) || !$this->isCsrfTokenValid('admin_password_change', $csrfToken)) {
                return $this->privateResponse(new Response('', Response::HTTP_FORBIDDEN));
            }

            if ([] !== array_diff(array_keys($submitted), ['_token', 'current_password', 'new_password', 'password_confirmation'])) {
                $errors[] = 'Die Anfrage enthält unbekannte Passwortfelder.';
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            } else {
                $currentPassword = $submitted['current_password'] ?? null;
                $newPassword = $submitted['new_password'] ?? null;
                $confirmation = $submitted['password_confirmation'] ?? null;

                if (!is_string($currentPassword) || !is_string($newPassword) || !is_string($confirmation)
                    || self::MAXIMUM_PASSWORD_BYTES < strlen($currentPassword)
                    || self::MAXIMUM_PASSWORD_BYTES < strlen($newPassword)
                    || self::MAXIMUM_PASSWORD_BYTES < strlen($confirmation)
                ) {
                    $errors[] = 'Bitte gib gültige Passwörter innerhalb der zulässigen Länge ein.';
                    $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                } elseif (1 !== preg_match('//u', $newPassword)
                    || 1 !== preg_match('//u', $confirmation)
                    || mb_strlen($newPassword, 'UTF-8') < self::MINIMUM_PASSWORD_CHARACTERS
                    || '' === trim($newPassword)
                ) {
                    $errors[] = 'Das neue Passwort muss gültiges UTF-8 enthalten und mindestens 16 Zeichen lang sein.';
                    $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                } elseif (!hash_equals($newPassword, $confirmation)) {
                    $errors[] = 'Die neuen Passwörter stimmen nicht überein.';
                    $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                } else {
                    $rateLimitKey = $this->rateLimitKeys->forIdentityAndIp(
                        $administrator->getId(),
                        $request,
                        'admin-password-change',
                    );

                    try {
                        if (!$this->passwordChangeLimiter->create($rateLimitKey)->consume()->isAccepted()) {
                            return $this->passwordPageResponse(
                                ['Zu viele Versuche. Bitte warte kurz und versuche es erneut.'],
                                Response::HTTP_TOO_MANY_REQUESTS,
                            );
                        }
                    } catch (\Throwable $exception) {
                        $this->logger->warning('Die Änderung eines Administratorkennworts wurde wegen eines nicht verfügbaren Drosselungsdienstes gesperrt.', [
                            'failure_type' => $exception::class,
                        ]);

                        return $this->unavailableResponse($translator);
                    }

                    $previousPasswordHash = $administrator->getPassword();
                    try {
                        if (!$this->passwordHasher->isPasswordValid($administrator, $currentPassword)) {
                            $errors[] = 'Das aktuelle Passwort ist falsch.';
                            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                        } elseif (hash_equals($currentPassword, $newPassword)) {
                            $errors[] = 'Das neue Passwort muss sich vom aktuellen Passwort unterscheiden.';
                            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                        } else {
                            $administrator->setPassword($this->passwordHasher->hashPassword($administrator, $newPassword));
                            $this->entityManager->persist(new AdminActivityEntry($administrator, 'admin_password_change', Request::METHOD_POST));
                            $this->entityManager->flush();
                        }
                    } catch (\Throwable $exception) {
                        $this->logger->error('Das Administratorpasswort konnte nicht sicher geändert werden.', [
                            'failure_type' => $exception::class,
                        ]);

                        if (null !== $previousPasswordHash) {
                            $administrator->setPassword($previousPasswordHash);
                        }

                        return $this->unavailableResponse($translator);
                    }

                    if ([] === $errors) {
                        if ($request->hasSession()) {
                            try {
                                $request->getSession()->invalidate();
                            } catch (\Throwable $exception) {
                                $this->logger->warning('Die Sitzung nach einer Administratorpasswortänderung konnte nicht separat invalidiert werden.', [
                                    'failure_type' => $exception::class,
                                ]);
                            }
                        }
                        $this->tokenStorage->setToken(null);
                        $this->addFlash('success', 'Das Administratorpasswort wurde geändert. Bitte melde dich mit deinem neuen Passwort und MFA erneut an.');

                        return $this->privateResponse($this->redirectToRoute('admin_login'));
                    }
                }
            }
        }

        return $this->passwordPageResponse($errors, $status);
    }

    /** @param list<string> $errors */
    private function passwordPageResponse(array $errors, int $status): Response
    {
        return $this->privateResponse($this->render('admin/security/password.html.twig', [
            'errors' => $errors,
        ], new Response(status: $status)));
    }

    private function unavailableResponse(TranslatorInterface $translator): Response
    {
        return $this->privateResponse(new Response(
            $translator->trans('Das Administratorpasswort ist derzeit nicht verfügbar. Bitte versuche es später erneut.'),
            Response::HTTP_SERVICE_UNAVAILABLE,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        ));
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
