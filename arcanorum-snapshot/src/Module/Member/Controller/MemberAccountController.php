<?php

declare(strict_types=1);

namespace App\Module\Member\Controller;

use App\Entity\MemberAccount;
use App\Entity\MemberExternalIdentity;
use App\Entity\SiteSettings;
use App\Module\Member\Security\GoogleOAuthClient;
use App\Module\Member\Security\MemberEmailChallengePurpose;
use App\Module\Member\Security\MemberSecurityMailService;
use App\Module\Member\Security\MemberSecurityRequestLimiter;
use App\Module\Member\Security\MemberSocialLoginAuthenticator;
use App\Platform\Security\RateLimitKeyFactory;
use App\Repository\MemberAccountRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;

#[Route('/konto', name: 'member_')]
final class MemberAccountController extends AbstractController
{
    public function __construct(private readonly RateLimitKeyFactory $rateLimitKeys)
    {
    }

    #[Route('/registrieren', name: 'register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        MemberSecurityRequestLimiter $securityLimiter,
        MemberSecurityMailService $mailService,
        LoggerInterface $logger,
    ): Response {
        $members = $entityManager->getRepository(MemberAccount::class);
        $values = ['email' => '', 'handle' => '', 'display_name' => ''];
        if (!$request->isMethod('POST')) {
            return $this->renderRegistration($values);
        }

        if (!$this->isCsrfTokenValid('member_register', $request->request->getString('_token'))) {
            return new Response('', Response::HTTP_FORBIDDEN, ['Cache-Control' => 'no-store']);
        }

        if ('' !== trim($request->request->getString('website'))) {
            return $this->noStore($this->redirectToRoute('member_login'));
        }

        $rawEmail = $request->request->getString('email');
        $rawHandle = $request->request->getString('handle');
        $rawDisplayName = $request->request->getString('display_name');
        $password = $request->request->getString('password');
        $values = [
            'email' => trim($rawEmail),
            'handle' => mb_strtolower(trim($rawHandle), 'UTF-8'),
            'display_name' => trim($rawDisplayName),
        ];
        $errors = [];

        if (strlen($rawEmail) > 1016
            || strlen($rawHandle) > 128
            || strlen($rawDisplayName) > 320
            || strlen($password) > 4096
        ) {
            $errors[] = 'Die Eingabe ist zu groß.';
        } else {
            if (1 !== preg_match('//u', $values['email'])
                || mb_strlen($values['email'], 'UTF-8') > 254
                || false === filter_var($values['email'], FILTER_VALIDATE_EMAIL)
            ) {
                $errors[] = 'Bitte gib eine gültige E-Mail-Adresse ein.';
            }
            if (1 !== preg_match('/\A[a-z0-9][a-z0-9_-]{2,31}\z/D', $values['handle'])) {
                $errors[] = 'Der Kanalname muss 3 bis 32 Zeichen lang sein und darf Buchstaben, Zahlen, Unterstriche und Bindestriche enthalten.';
            }
            if (1 !== preg_match('//u', $values['display_name'])
                || 1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $values['display_name'])
                || '' === $values['display_name']
                || mb_strlen($values['display_name'], 'UTF-8') > 80
            ) {
                $errors[] = 'Der Anzeigename muss 1 bis 80 Zeichen lang sein.';
            }
            if (1 !== preg_match('//u', $password)
                || mb_strlen($password, 'UTF-8') < 12
                || '' === trim($password)
            ) {
                $errors[] = 'Das Passwort muss mindestens 12 Zeichen enthalten.';
            }
        }

        if ([] !== $errors) {
            return $this->renderRegistration($values, $errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $session = $request->getSession();
        $lastRegistrationAt = $session->get('member_registration_last_at');
        if (is_int($lastRegistrationAt) && time() - $lastRegistrationAt < 60) {
            return $this->renderRegistration(
                $values,
                ['Bitte warte kurz, bevor du ein weiteres Konto erstellst.'],
                Response::HTTP_TOO_MANY_REQUESTS,
            );
        }
        $session->set('member_registration_last_at', time());
        $normalizedEmail = mb_strtolower($values['email'], 'UTF-8');

        try {
            if (!$securityLimiter->allow($request, $normalizedEmail)) {
                return $this->renderRegistration(
                    $values,
                    ['Bitte warte kurz, bevor du ein weiteres Konto erstellst.'],
                    Response::HTTP_TOO_MANY_REQUESTS,
                );
            }
        } catch (\Throwable) {
            $logger->warning('Eine Mitgliedsregistrierung konnte nicht sicher gedrosselt werden.');

            return $this->renderRegistration($values, ['Die Registrierung ist derzeit nicht verfügbar.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (null !== $members->findOneBy(['email' => $normalizedEmail])
            || null !== $members->findOneBy(['handle' => $values['handle']])
        ) {
            return $this->renderRegistration(
                $values,
                ['Diese Kombination aus E-Mail-Adresse oder Kanalname ist nicht verfügbar.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $member = new MemberAccount($normalizedEmail, $values['handle'], $values['display_name']);
        $member->requireEmailVerification();
        $member->setPassword($passwordHasher->hashPassword($member, $password));

        try {
            $entityManager->persist($member);
            $entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->renderRegistration(
                $values,
                ['Diese Kombination aus E-Mail-Adresse oder Kanalname ist nicht verfügbar.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $mailService->send($member, MemberEmailChallengePurpose::EmailVerification);
        $this->addFlash('member_success', 'Dein Konto wurde erstellt. Prüfe dein Postfach und bestätige deine E-Mail-Adresse.');

        return $this->noStore($this->redirectToRoute('member_login'));
    }

    #[Route('/sozial/google', name: 'google_start', methods: ['GET'])]
    public function startGoogleLogin(
        Request $request,
        EntityManagerInterface $entityManager,
        #[Autowire(service: GoogleOAuthClient::class)] GoogleOAuthClient $google,
        #[Autowire(service: 'limiter.member_social_login_start')] RateLimiterFactory $rateLimiter,
        LoggerInterface $logger,
    ): Response {
        if ($this->getUser() instanceof MemberAccount) {
            return $this->noStore($this->redirectToRoute('member_profile'));
        }

        if (!$this->googleLoginEnabled($entityManager) || !$google->isConfigured()) {
            return $this->googleFailure('Die Google-Anmeldung ist derzeit nicht verfügbar.');
        }

        return $this->startGoogleFlow($request, $google, $rateLimiter, $logger, 'login');
    }

    #[Route('/sozial/google/verknuepfen', name: 'google_link', methods: ['POST'])]
    public function linkGoogleAccount(
        Request $request,
        EntityManagerInterface $entityManager,
        #[Autowire(service: GoogleOAuthClient::class)] GoogleOAuthClient $google,
        #[Autowire(service: 'limiter.member_social_login_start')] RateLimiterFactory $rateLimiter,
        LoggerInterface $logger,
    ): Response {
        $member = $this->getUser();
        if (!$member instanceof MemberAccount || !$member->isActive() || !$member->isEmailVerified()) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('member_google_link', $request->request->getString('_token'))) {
            return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
        }
        if (!$this->googleLoginEnabled($entityManager) || !$google->isConfigured()) {
            $this->addFlash('member_error', 'Die Google-Verknüpfung ist derzeit nicht verfügbar.');

            return $this->noStore($this->redirectToRoute('member_profile'));
        }

        $identities = $entityManager->getRepository(MemberExternalIdentity::class);
        if (!$identities instanceof \App\Repository\MemberExternalIdentityRepository) {
            throw new \LogicException('Das Mitgliedsidentitätsrepository ist nicht verfügbar.');
        }
        if (null !== $identities->findForMember($member, 'google')) {
            $this->addFlash('member_success', 'Ein Google-Konto ist bereits mit deinem Profil verknüpft.');

            return $this->noStore($this->redirectToRoute('member_profile'));
        }

        return $this->startGoogleFlow($request, $google, $rateLimiter, $logger, 'link', $member);
    }

    #[Route('/sozial/google/callback', name: 'google_callback', methods: ['GET'])]
    public function googleCallback(
        Request $request,
        EntityManagerInterface $entityManager,
        #[Autowire(service: GoogleOAuthClient::class)] GoogleOAuthClient $google,
        #[Autowire(service: 'limiter.member_social_login_callback')] RateLimiterFactory $rateLimiter,
        #[Autowire(service: 'security.user_authenticator')] UserAuthenticatorInterface $userAuthenticator,
        LoggerInterface $logger,
    ): Response {
        try {
            $limiterKey = $this->rateLimitKeys->forIp($request, 'member-social-login-callback');
            if (!$rateLimiter->create($limiterKey)->consume()->isAccepted()) {
                return $this->noStore(new Response('', Response::HTTP_TOO_MANY_REQUESTS));
            }
        } catch (\Throwable $exception) {
            $logger->warning('Google-Anmeldung ist wegen eines nicht verfügbaren Drosselungsdienstes gesperrt.', [
                'failure_type' => $exception::class,
            ]);

            return $this->noStore(new Response('', Response::HTTP_SERVICE_UNAVAILABLE));
        }

        $session = $request->getSession();
        $pending = $session->get('member_google_oauth_pending');
        $state = $request->query->get('state');
        if (!is_array($pending)
            || !is_string($pending['state'] ?? null)
            || !is_string($pending['verifier'] ?? null)
            || !is_string($pending['intent'] ?? null)
            || !in_array($pending['intent'], ['login', 'link'], true)
            || !is_int($pending['created_at'] ?? null)
            || time() - $pending['created_at'] > 600
            || !is_string($state)
            || strlen($state) > 128
            || !hash_equals($pending['state'], $state)
        ) {
            return $this->googleFailure('Die Google-Anmeldung ist abgelaufen oder ungültig. Bitte versuche es erneut.');
        }
        $session->remove('member_google_oauth_pending');

        $providerError = $request->query->get('error');
        $code = $request->query->get('code');
        if (null !== $providerError
            || !is_string($code)
            || '' === $code
            || strlen($code) > 4096
            || !$google->isConfigured()
            || !$this->googleLoginEnabled($entityManager)
        ) {
            return $this->googleFailure('Die Google-Anmeldung konnte nicht abgeschlossen werden. Bitte versuche es erneut.');
        }

        try {
            $profile = $google->fetchVerifiedProfile($code, $pending['verifier']);
        } catch (\Throwable $exception) {
            $logger->warning('Die bestätigte Google-Profilidentität konnte nicht abgerufen werden.', [
                'failure_type' => $exception::class,
            ]);

            return $this->googleFailure('Die Google-Anmeldung konnte nicht bestätigt werden. Bitte verwende eine andere Anmeldemethode.');
        }

        $identityRepository = $entityManager->getRepository(MemberExternalIdentity::class);
        $members = $entityManager->getRepository(MemberAccount::class);
        if (!$identityRepository instanceof \App\Repository\MemberExternalIdentityRepository
            || !$members instanceof MemberAccountRepository
        ) {
            throw new \LogicException('Die Mitgliedsrepositories sind nicht verfügbar.');
        }

        if ('link' === $pending['intent']) {
            $member = $this->getUser();
            $expectedMemberId = $pending['member_id'] ?? null;
            if (!$member instanceof MemberAccount
                || !is_string($expectedMemberId)
                || !hash_equals($member->getId(), $expectedMemberId)
                || !$member->isActive()
                || !$member->isEmailVerified()
            ) {
                return $this->googleFailure('Melde dich erneut an und starte die Google-Verknüpfung noch einmal.');
            }

            $existingIdentity = $identityRepository->findByProviderSubject('google', $profile->subject);
            if (null !== $existingIdentity) {
                $this->addFlash('member_error', $existingIdentity->getMember()->getId() === $member->getId()
                    ? 'Dieses Google-Konto ist bereits mit deinem Profil verknüpft.'
                    : 'Dieses Google-Konto kann nicht mit diesem Profil verknüpft werden.');

                return $this->noStore($this->redirectToRoute('member_profile'));
            }
            if (null !== $identityRepository->findForMember($member, 'google')) {
                $this->addFlash('member_error', 'Dein Profil besitzt bereits eine Google-Verknüpfung.');

                return $this->noStore($this->redirectToRoute('member_profile'));
            }

            try {
                $entityManager->persist(new MemberExternalIdentity($member, 'google', $profile->subject));
                $entityManager->flush();
            } catch (\Throwable $exception) {
                $logger->warning('Eine Google-Mitgliedsverknüpfung konnte nicht gespeichert werden.', [
                    'failure_type' => $exception::class,
                ]);
                $this->addFlash('member_error', 'Die Google-Verknüpfung konnte nicht gespeichert werden.');

                return $this->noStore($this->redirectToRoute('member_profile'));
            }

            $this->addFlash('member_success', 'Dein Google-Konto wurde verknüpft.');

            return $this->noStore($this->redirectToRoute('member_profile'));
        }

        $identity = $identityRepository->findByProviderSubject('google', $profile->subject);
        if (null !== $identity) {
            $member = $identity->getMember();
            if (!$member->isActive() || !$member->isEmailVerified()) {
                return $this->googleFailure('Die Google-Anmeldung konnte nicht bestätigt werden. Bitte verwende eine andere Anmeldemethode.');
            }

            try {
                $response = $userAuthenticator->authenticateUser($member, new MemberSocialLoginAuthenticator(), $request);
            } catch (\Throwable $exception) {
                $logger->error('Ein Google-Mitglied konnte nicht sicher angemeldet werden.', [
                    'failure_type' => $exception::class,
                ]);

                return $this->googleFailure('Die Anmeldung konnte nicht abgeschlossen werden. Bitte versuche es erneut.');
            }

            return $this->noStore($response ?? $this->redirectToRoute('member_profile'));
        }

        if (null !== $members->findOneBy(['email' => $profile->email])) {
            return $this->googleFailure('Für diese E-Mail-Adresse besteht bereits ein Konto. Melde dich mit deinem Passwort an und verknüpfe Google danach im Profil.');
        }
        if (!$this->settingsFrom($entityManager)->isMemberRegistrationEnabled()) {
            return $this->googleFailure('Neue Mitgliedskonten sind derzeit nicht verfügbar. Bestehende Google-Konten können sich weiterhin anmelden.');
        }

        try {
            $member = new MemberAccount($profile->email, 'google-'.bin2hex(random_bytes(6)), $profile->displayName);
            $identity = new MemberExternalIdentity($member, 'google', $profile->subject);
            $entityManager->wrapInTransaction(static function (EntityManagerInterface $transactionalEntityManager) use ($member, $identity): void {
                $transactionalEntityManager->persist($member);
                $transactionalEntityManager->persist($identity);
                $transactionalEntityManager->flush();
            });
        } catch (\Throwable $exception) {
            $logger->warning('Ein Google-Mitgliedskonto konnte nicht sicher erstellt werden.', [
                'failure_type' => $exception::class,
            ]);

            return $this->googleFailure('Das Mitgliedskonto konnte nicht erstellt werden. Prüfe deine Angaben und versuche es erneut.');
        }

        try {
            $response = $userAuthenticator->authenticateUser($member, new MemberSocialLoginAuthenticator(), $request);
        } catch (\Throwable $exception) {
            $logger->error('Ein neues Google-Mitglied konnte nicht sicher angemeldet werden.', [
                'failure_type' => $exception::class,
            ]);

            return $this->googleFailure('Das Konto wurde erstellt, die Anmeldung konnte aber nicht abgeschlossen werden. Melde dich bitte mit Google erneut an.');
        }

        return $this->noStore($response ?? $this->redirectToRoute('member_profile'));
    }

    #[Route('/sozial/google/trennen', name: 'google_unlink', methods: ['POST'])]
    public function unlinkGoogleAccount(Request $request, EntityManagerInterface $entityManager): Response
    {
        $member = $this->getUser();
        if (!$member instanceof MemberAccount || !$member->isActive() || !$member->isEmailVerified()) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('member_google_unlink', $request->request->getString('_token'))) {
            return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
        }

        $identities = $entityManager->getRepository(MemberExternalIdentity::class);
        if (!$identities instanceof \App\Repository\MemberExternalIdentityRepository) {
            throw new \LogicException('Das Mitgliedsidentitätsrepository ist nicht verfügbar.');
        }
        $identity = $identities->findForMember($member, 'google');
        if (null === $identity) {
            $this->addFlash('member_error', 'Für dein Profil besteht keine Google-Verknüpfung.');

            return $this->noStore($this->redirectToRoute('member_profile'));
        }
        if (!$member->hasUsablePasswordCredential()) {
            $this->addFlash('member_error', 'Lege zuerst ein lokales Passwort fest, bevor du deine letzte externe Anmeldung entfernst.');

            return $this->noStore($this->redirectToRoute('member_profile'));
        }

        $entityManager->remove($identity);
        $entityManager->flush();
        $this->addFlash('member_success', 'Die Google-Verknüpfung wurde entfernt.');

        return $this->noStore($this->redirectToRoute('member_profile'));
    }

    /** @param positive-int $bytes */
    private function oauthToken(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    private function startGoogleFlow(
        Request $request,
        GoogleOAuthClient $google,
        RateLimiterFactory $rateLimiter,
        LoggerInterface $logger,
        string $intent,
        ?MemberAccount $member = null,
    ): Response {
        try {
            $limiterKey = $this->rateLimitKeys->forIp($request, 'member-social-login-start');
            if (!$rateLimiter->create($limiterKey)->consume()->isAccepted()) {
                return $this->noStore(new Response('', Response::HTTP_TOO_MANY_REQUESTS));
            }
            $state = $this->oauthToken(32);
            $verifier = $this->oauthToken(64);
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            $request->getSession()->set('member_google_oauth_pending', [
                'state' => $state,
                'verifier' => $verifier,
                'intent' => $intent,
                'member_id' => $member?->getId(),
                'created_at' => time(),
            ]);

            return $this->noStore($this->redirect($google->authorizationUrl($state, $challenge)));
        } catch (\Throwable $exception) {
            $logger->warning('Der Google-Anmeldeablauf konnte nicht sicher gestartet werden.', [
                'failure_type' => $exception::class,
            ]);

            return $this->noStore(new Response('', Response::HTTP_SERVICE_UNAVAILABLE));
        }
    }

    private function googleLoginEnabled(EntityManagerInterface $entityManager): bool
    {
        return $this->settingsFrom($entityManager)->isGoogleSocialLoginEnabled();
    }

    private function settingsFrom(EntityManagerInterface $entityManager): SiteSettings
    {
        $settings = $entityManager->getRepository(SiteSettings::class)->find(SiteSettings::SINGLETON_ID);

        return $settings instanceof SiteSettings ? $settings : new SiteSettings();
    }

    private function googleFailure(string $message): Response
    {
        $this->addFlash('member_error', $message);

        return $this->noStore($this->redirectToRoute('member_login'));
    }

    #[Route('/einrichten', name: 'setup', methods: ['GET', 'POST'])]
    public function setup(
        Request $request,
        MemberAccountRepository $members,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
    ): Response {
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('member_setup', $request->request->getString('_token'))) {
                return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
            }

            $code = trim($request->request->getString('invitation_code'));
            $password = $request->request->getString('password');
            $confirmation = $request->request->getString('password_confirmation');

            if (strlen($code) > 128 || strlen($password) > 4096 || strlen($confirmation) > 4096
                || 1 !== preg_match('//u', $password)
                || 1 !== preg_match('//u', $confirmation)
                || mb_strlen($password, 'UTF-8') < 12
                || '' === trim($password)
            ) {
                $errors[] = 'Das Passwort muss gültiges UTF-8 enthalten und mindestens 12 Zeichen lang sein.';
            } elseif ($password !== $confirmation) {
                $errors[] = 'Die Passwörter stimmen nicht überein.';
            } elseif (1 !== preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $code)) {
                $errors[] = 'Der Einrichtungscode ist ungültig oder abgelaufen.';
            } else {
                $tokenHash = hash('sha256', $code);
                $account = $members->findByInvitationTokenHash($tokenHash);
                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

                if (null === $account || $account->isActive()
                    || null === $account->getInvitationExpiresAt()
                    || $account->getInvitationExpiresAt() <= $now
                ) {
                    $errors[] = 'Der Einrichtungscode ist ungültig oder abgelaufen.';
                } else {
                    $passwordHash = $passwordHasher->hashPassword($account, $password);
                    if (!$members->consumeInvitation($tokenHash, $passwordHash, $now)) {
                        $errors[] = 'Der Einrichtungscode ist ungültig oder abgelaufen.';
                    } else {
                        $entityManager->clear();
                        $this->addFlash('member_success', 'Dein Passwort wurde festgelegt. Du kannst dich jetzt anmelden.');

                        return $this->noStore($this->redirectToRoute('member_login'));
                    }
                }
            }
        }

        $response = $this->render('member/setup.html.twig', [
            'errors' => $errors,
        ]);
        $response->setStatusCode([] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);

        return $this->noStore($response);
    }

    #[Route('/anmelden', name: 'login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        $response = $this->render('member/account.html.twig', [
            'page' => 'login',
            'last_username' => $authenticationUtils->getLastUsername(),
            'login_error' => null !== $authenticationUtils->getLastAuthenticationError(),
        ]);

        return $this->noStore($response);
    }

    #[Route('/passwort', name: 'password', methods: ['GET', 'POST'])]
    public function changePassword(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        TokenStorageInterface $tokenStorage,
        #[Autowire(service: 'limiter.member_password_change')] RateLimiterFactory $passwordChangeLimiter,
    ): Response {
        $member = $this->getUser();
        if (!$member instanceof MemberAccount) {
            throw $this->createAccessDeniedException();
        }

        $errors = [];
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('member_password', $request->request->getString('_token'))) {
                return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
            }

            $currentPassword = $request->request->getString('current_password');
            $newPassword = $request->request->getString('new_password');
            $confirmation = $request->request->getString('password_confirmation');

            if (strlen($currentPassword) > 4096
                || strlen($newPassword) > 4096
                || strlen($confirmation) > 4096
                || 1 !== preg_match('//u', $newPassword)
                || 1 !== preg_match('//u', $confirmation)
                || mb_strlen($newPassword, 'UTF-8') < 12
                || '' === trim($newPassword)
            ) {
                $errors[] = 'Das neue Passwort muss gültiges UTF-8 enthalten und mindestens 12 Zeichen lang sein.';
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            } elseif ($newPassword !== $confirmation) {
                $errors[] = 'Die neuen Passwörter stimmen nicht überein.';
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            } else {
                $limiterKey = $this->rateLimitKeys->forIdentityAndIp($member->getId(), $request, 'member-password-change');
                $rateLimit = $passwordChangeLimiter->create($limiterKey)->consume();

                if (!$rateLimit->isAccepted()) {
                    $errors[] = 'Zu viele Versuche. Bitte warte kurz und versuche es erneut.';
                    $status = Response::HTTP_TOO_MANY_REQUESTS;
                } elseif (!$passwordHasher->isPasswordValid($member, $currentPassword)) {
                    $errors[] = 'Das aktuelle Passwort ist falsch.';
                    $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                } else {
                    $member->setPassword($passwordHasher->hashPassword($member, $newPassword));
                    $entityManager->flush();
                    $tokenStorage->setToken(null);
                    $this->addFlash('member_success', 'Dein Passwort wurde geändert. Melde dich erneut an.');

                    return $this->noStore($this->redirectToRoute('member_login'));
                }
            }
        }

        $response = $this->render(
            'member/account.html.twig',
            ['page' => 'password', 'member' => $member, 'errors' => $errors],
            new Response(status: $status),
        );

        return $this->noStore($response);
    }

    #[Route('/profil', name: 'profile', methods: ['GET', 'POST'])]
    public function profile(Request $request, EntityManagerInterface $entityManager): Response
    {
        $member = $this->getUser();
        if (!$member instanceof MemberAccount) {
            throw $this->createAccessDeniedException();
        }

        $values = [
            'display_name' => $member->getDisplayName(),
            'bio' => $member->getBio() ?? '',
            'visibility' => $member->isPublicProfile() ? 'public' : 'private',
        ];
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('member_profile', $request->request->getString('_token'))) {
                return new Response('', Response::HTTP_FORBIDDEN, ['Cache-Control' => 'no-store']);
            }

            $rawDisplayName = $request->request->getString('display_name');
            $rawBio = $request->request->getString('bio');
            $visibility = $request->request->getString('visibility');
            $values = [
                'display_name' => trim($rawDisplayName),
                'bio' => trim($rawBio),
                'visibility' => $visibility,
            ];

            if (strlen($rawDisplayName) > 320
                || strlen($rawBio) > 2000
                || !in_array($visibility, ['public', 'private'], true)
            ) {
                $errors[] = 'Die Eingabe ist ungültig oder zu groß.';
            } else {
                try {
                    $member->updateProfile(
                        $values['display_name'],
                        $values['bio'],
                        'public' === $visibility,
                    );
                } catch (\InvalidArgumentException) {
                    $errors[] = 'Anzeigename oder Beschreibung enthält ungültige Angaben.';
                }
            }

            if ([] === $errors) {
                $entityManager->flush();
                $this->addFlash('member_success', 'Dein Profil wurde gespeichert.');

                return $this->noStore($this->redirectToRoute('member_profile'));
            }
        }

        $response = $this->render('member/account.html.twig', [
            'page' => 'profile',
            'member' => $member,
            'values' => $values,
            'errors' => $errors,
        ]);

        return $this->noStore($response);
    }

    #[Route('/abmelden', name: 'logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Diese Route wird vom Mitglieds-Logout abgefangen.');
    }

    /**
     * @param array{email: string, handle: string, display_name: string} $values
     * @param list<string>                                               $errors
     */
    private function renderRegistration(
        array $values,
        array $errors = [],
        int $status = Response::HTTP_OK,
    ): Response {
        $response = $this->render(
            'member/account.html.twig',
            ['page' => 'register', 'values' => $values, 'errors' => $errors],
            new Response(status: $status),
        );

        return $this->noStore($response);
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
