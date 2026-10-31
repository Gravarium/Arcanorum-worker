<?php

declare(strict_types=1);

namespace App\Module\Member\Controller;

use App\Entity\MemberAccount;
use App\Module\Member\Security\MemberEmailChallengeManager;
use App\Module\Member\Security\MemberEmailChallengePurpose;
use App\Module\Member\Security\MemberSecurityMailService;
use App\Module\Member\Security\MemberSecurityRequestLimiter;
use App\Repository\MemberAccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

#[Route('/konto', name: 'member_')]
final class MemberSecurityController extends AbstractController
{
    #[Route('/verifizierung', name: 'email_request', methods: ['GET', 'POST'])]
    public function requestEmailVerification(
        Request $request,
        MemberAccountRepository $members,
        MemberSecurityRequestLimiter $limiter,
        MemberSecurityMailService $mailService,
        LoggerInterface $logger,
    ): Response {
        if (!$request->isMethod('POST')) {
            return $this->renderSecurityPage('email_request');
        }

        if (!$this->isCsrfTokenValid('member_email_request', $this->field($request, '_token') ?? '')) {
            return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
        }

        $email = $this->normalizedEmail($this->field($request, 'email'));
        try {
            if (!$limiter->allow($request, $email)) {
                return $this->renderSecurityPage('email_request', ['throttled' => true], Response::HTTP_TOO_MANY_REQUESTS);
            }

            if ('' !== trim($this->field($request, 'website') ?? '')) {
                return $this->renderSecurityPage('email_request', ['submitted' => true]);
            }

            if ($this->validEmail($email)) {
                $member = $members->findOneBy(['email' => $email]);
                if ($member instanceof MemberAccount && $member->isActive() && !$member->isEmailVerified()) {
                    $mailService->send($member, MemberEmailChallengePurpose::EmailVerification);
                }
            }
        } catch (\Throwable) {
            $logger->warning('Eine Mitglieds-Verifizierungsanfrage konnte nicht abgeschlossen werden.');

            return $this->renderSecurityPage('email_request', ['submitted' => true], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->renderSecurityPage('email_request', ['submitted' => true]);
    }

    #[Route('/passwort-vergessen', name: 'password_reset_request', methods: ['GET', 'POST'])]
    public function requestPasswordReset(
        Request $request,
        MemberAccountRepository $members,
        MemberSecurityRequestLimiter $limiter,
        MemberSecurityMailService $mailService,
        LoggerInterface $logger,
    ): Response {
        if (!$request->isMethod('POST')) {
            return $this->renderSecurityPage('password_reset_request');
        }

        if (!$this->isCsrfTokenValid('member_password_reset_request', $this->field($request, '_token') ?? '')) {
            return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
        }

        $email = $this->normalizedEmail($this->field($request, 'email'));
        try {
            if (!$limiter->allow($request, $email)) {
                return $this->renderSecurityPage('password_reset_request', ['throttled' => true], Response::HTTP_TOO_MANY_REQUESTS);
            }

            if ('' !== trim($this->field($request, 'website') ?? '')) {
                return $this->renderSecurityPage('password_reset_request', ['submitted' => true]);
            }

            if ($this->validEmail($email)) {
                $member = $members->findOneBy(['email' => $email]);
                if ($member instanceof MemberAccount && $member->isActive() && $member->isEmailVerified()) {
                    $mailService->send($member, MemberEmailChallengePurpose::PasswordReset);
                }
            }
        } catch (\Throwable) {
            $logger->warning('Eine Mitglieds-Passwortanfrage konnte nicht abgeschlossen werden.');

            return $this->renderSecurityPage('password_reset_request', ['submitted' => true], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->renderSecurityPage('password_reset_request', ['submitted' => true]);
    }

    #[Route('/verifizieren/{token}', name: 'email_verify', requirements: ['token' => '[A-Za-z0-9_-]{43}'], methods: ['GET', 'POST'])]
    public function verifyEmail(
        Request $request,
        string $token,
        MemberEmailChallengeManager $challenges,
        MemberSecurityRequestLimiter $limiter,
        EntityManagerInterface $entityManager,
        LoggerInterface $logger,
    ): Response {
        if (!$request->isMethod('POST')) {
            $isValid = null !== $challenges->findEligibleMemberId(
                $token,
                MemberEmailChallengePurpose::EmailVerification,
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            );

            return $this->renderSecurityPage('email_verify', ['token' => $token, 'valid' => $isValid], $isValid ? Response::HTTP_OK : Response::HTTP_NOT_FOUND);
        }

        if (!$this->isCsrfTokenValid('member_email_verify', $this->field($request, '_token') ?? '')) {
            return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
        }

        try {
            if (!$limiter->allow($request, hash('sha256', $token))) {
                return $this->renderSecurityPage('email_verify', ['token' => $token, 'valid' => true, 'throttled' => true], Response::HTTP_TOO_MANY_REQUESTS);
            }

            if (!$challenges->consumeEmailVerification($token, new \DateTimeImmutable('now', new \DateTimeZone('UTC')))) {
                return $this->renderSecurityPage('email_verify', ['token' => $token, 'valid' => false], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        } catch (\Throwable) {
            $logger->warning('Eine Mitglieds-E-Mail konnte nicht bestätigt werden.');

            return $this->renderSecurityPage('email_verify', ['token' => $token, 'valid' => false], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $entityManager->clear();
        $this->addFlash('member_success', 'Deine E-Mail-Adresse wurde bestätigt. Du kannst dich jetzt anmelden.');

        return $this->noStore($this->redirectToRoute('member_login'));
    }

    #[Route('/passwort-zuruecksetzen/{token}', name: 'password_reset', requirements: ['token' => '[A-Za-z0-9_-]{43}'], methods: ['GET', 'POST'])]
    public function resetPassword(
        Request $request,
        string $token,
        MemberEmailChallengeManager $challenges,
        MemberSecurityRequestLimiter $limiter,
        MemberAccountRepository $members,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        TokenStorageInterface $tokenStorage,
        LoggerInterface $logger,
    ): Response {
        if (!$request->isMethod('POST')) {
            $isValid = null !== $challenges->findEligibleMemberId(
                $token,
                MemberEmailChallengePurpose::PasswordReset,
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            );

            return $this->renderSecurityPage('password_reset', ['token' => $token, 'valid' => $isValid], $isValid ? Response::HTTP_OK : Response::HTTP_NOT_FOUND);
        }

        if (!$this->isCsrfTokenValid('member_password_reset', $this->field($request, '_token') ?? '')) {
            return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
        }

        try {
            if (!$limiter->allow($request, hash('sha256', $token))) {
                return $this->renderSecurityPage('password_reset', ['token' => $token, 'valid' => true, 'throttled' => true], Response::HTTP_TOO_MANY_REQUESTS);
            }
        } catch (\Throwable) {
            $logger->warning('Eine Mitglieds-Passwortanfrage konnte nicht gedrosselt werden.');

            return $this->renderSecurityPage('password_reset', ['token' => $token, 'valid' => false], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $password = $this->field($request, 'password');
        $confirmation = $this->field($request, 'password_confirmation');
        $validPassword = null !== $password
            && null !== $confirmation
            && strlen($password) <= 4096
            && strlen($confirmation) <= 4096
            && 1 === preg_match('//u', $password)
            && 1 === preg_match('//u', $confirmation)
            && mb_strlen($password, 'UTF-8') >= 12
            && '' !== trim($password);
        if (!$validPassword || $password !== $confirmation) {
            $isValid = null !== $challenges->findEligibleMemberId(
                $token,
                MemberEmailChallengePurpose::PasswordReset,
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            );

            return $this->renderSecurityPage(
                'password_reset',
                ['token' => $token, 'valid' => $isValid, 'invalid_password' => true],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $memberId = $challenges->findEligibleMemberId(
                $token,
                MemberEmailChallengePurpose::PasswordReset,
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            );
            $member = null === $memberId ? null : $members->find($memberId);
            if (!$member instanceof MemberAccount
                || !$challenges->consumePasswordReset(
                    $token,
                    $passwordHasher->hashPassword($member, $password),
                    new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                )
            ) {
                return $this->renderSecurityPage('password_reset', ['token' => $token, 'valid' => false], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        } catch (\Throwable) {
            $logger->warning('Ein Mitgliedspasswort konnte nicht zurückgesetzt werden.');

            return $this->renderSecurityPage('password_reset', ['token' => $token, 'valid' => false], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $entityManager->clear();
        $tokenStorage->setToken(null);
        $request->getSession()->invalidate();
        $this->addFlash('member_success', 'Dein Passwort wurde geändert. Melde dich mit dem neuen Passwort an.');

        return $this->noStore($this->redirectToRoute('member_login'));
    }

    /** @param array<string, mixed> $context */
    private function renderSecurityPage(string $page, array $context = [], int $status = Response::HTTP_OK): Response
    {
        $response = $this->render('member/security_email.html.twig', array_replace([
            'page' => $page,
            'submitted' => false,
            'throttled' => false,
            'valid' => false,
            'invalid_password' => false,
            'token' => '',
        ], $context), new Response(status: $status));

        return $this->noStore($response);
    }

    private function field(Request $request, string $name): ?string
    {
        $value = $request->request->all()[$name] ?? null;

        return is_string($value) && strlen($value) <= 4096 ? $value : null;
    }

    private function normalizedEmail(?string $email): string
    {
        return null !== $email && 1 === preg_match('//u', $email)
            ? mb_strtolower(trim($email), 'UTF-8')
            : '';
    }

    private function validEmail(string $email): bool
    {
        return '' !== $email && mb_strlen($email, 'UTF-8') <= 254 && false !== filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
