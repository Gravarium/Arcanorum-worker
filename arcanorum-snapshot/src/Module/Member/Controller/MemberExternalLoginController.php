<?php

declare(strict_types=1);

namespace App\Module\Member\Controller;

use App\Entity\MemberAccount;
use App\Entity\MemberExternalIdentity;
use App\Entity\SiteSettings;
use App\Module\Member\Security\FacebookOAuthClient;
use App\Module\Member\Security\MemberEmailChallengePurpose;
use App\Module\Member\Security\MemberSecurityMailService;
use App\Module\Member\Security\MemberSecurityRequestLimiter;
use App\Module\Member\Security\MemberSocialLoginAuthenticator;
use App\Module\Member\Security\SocialOAuthClientInterface;
use App\Module\Member\Security\SocialOAuthProfile;
use App\Module\Member\Security\TwitterOAuthClient;
use App\Platform\Security\RateLimitKeyFactory;
use App\Repository\MemberAccountRepository;
use App\Repository\MemberExternalIdentityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;

#[Route('/konto', name: 'member_')]
final class MemberExternalLoginController extends AbstractController
{
    public function __construct(private readonly RateLimitKeyFactory $rateLimitKeys)
    {
    }

    #[Route('/sozial/{provider}', name: 'social_start', requirements: ['provider' => 'facebook|twitter'], methods: ['GET'])]
    public function start(
        string $provider,
        Request $request,
        EntityManagerInterface $entityManager,
        FacebookOAuthClient $facebook,
        TwitterOAuthClient $twitter,
        #[Autowire(service: 'limiter.member_social_login_start')] RateLimiterFactory $rateLimiter,
        LoggerInterface $logger,
    ): Response {
        if ($this->getUser() instanceof MemberAccount) {
            return $this->noStore($this->redirectToRoute('member_profile'));
        }

        $client = $this->client($provider, $facebook, $twitter);
        if (!$this->providerEnabled($provider, $entityManager) || !$client->isConfigured()) {
            return $this->failure('Die soziale Anmeldung ist derzeit nicht verfügbar.');
        }

        return $this->startFlow($request, $client, $rateLimiter, $logger, 'login');
    }

    #[Route('/sozial/{provider}/verknuepfen', name: 'social_link', requirements: ['provider' => 'facebook|twitter'], methods: ['POST'])]
    public function link(
        string $provider,
        Request $request,
        EntityManagerInterface $entityManager,
        FacebookOAuthClient $facebook,
        TwitterOAuthClient $twitter,
        #[Autowire(service: 'limiter.member_social_login_start')] RateLimiterFactory $rateLimiter,
        LoggerInterface $logger,
    ): Response {
        $member = $this->getUser();
        if (!$member instanceof MemberAccount || !$member->isActive() || !$member->isEmailVerified()) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('member_social_link_'.$provider, $request->request->getString('_token'))) {
            return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
        }

        $client = $this->client($provider, $facebook, $twitter);
        if (!$this->providerEnabled($provider, $entityManager) || !$client->isConfigured()) {
            $this->addFlash('member_error', 'Die soziale Verknüpfung ist derzeit nicht verfügbar.');

            return $this->noStore($this->redirectToRoute('member_profile'));
        }

        $identities = $entityManager->getRepository(MemberExternalIdentity::class);
        if (!$identities instanceof MemberExternalIdentityRepository) {
            throw new \LogicException('Das Mitgliedsidentitätsrepository ist nicht verfügbar.');
        }
        if (null !== $identities->findForMember($member, $provider)) {
            $this->addFlash('member_success', 'Dieses externe Konto ist bereits mit deinem Profil verknüpft.');

            return $this->noStore($this->redirectToRoute('member_profile'));
        }

        return $this->startFlow($request, $client, $rateLimiter, $logger, 'link', $member);
    }

    #[Route('/sozial/{provider}/callback', name: 'social_callback', requirements: ['provider' => 'facebook|twitter'], methods: ['GET'])]
    public function callback(
        string $provider,
        Request $request,
        EntityManagerInterface $entityManager,
        FacebookOAuthClient $facebook,
        TwitterOAuthClient $twitter,
        #[Autowire(service: 'limiter.member_social_login_callback')] RateLimiterFactory $rateLimiter,
        #[Autowire(service: 'security.user_authenticator')] UserAuthenticatorInterface $userAuthenticator,
        LoggerInterface $logger,
    ): Response {
        $client = $this->client($provider, $facebook, $twitter);
        try {
            $limiterKey = $this->rateLimitKeys->forIp($request, 'member-social-login-callback');
            if (!$rateLimiter->create($limiterKey)->consume()->isAccepted()) {
                return $this->noStore(new Response('', Response::HTTP_TOO_MANY_REQUESTS));
            }
        } catch (\Throwable $exception) {
            $logger->warning('Eine soziale Anmeldung ist wegen eines nicht verfügbaren Drosselungsdienstes gesperrt.', [
                'provider' => $provider,
                'failure_type' => $exception::class,
            ]);

            return $this->noStore(new Response('', Response::HTTP_SERVICE_UNAVAILABLE));
        }

        $session = $request->getSession();
        $pending = $session->get('member_'.$provider.'_oauth_pending');
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
            return $this->failure('Die soziale Anmeldung ist abgelaufen oder ungültig. Bitte versuche es erneut.');
        }
        $session->remove('member_'.$provider.'_oauth_pending');

        $code = $request->query->get('code');
        if (null !== $request->query->get('error')
            || !is_string($code)
            || '' === $code
            || strlen($code) > 4096
            || !$client->isConfigured()
            || !$this->providerEnabled($provider, $entityManager)
        ) {
            return $this->failure('Die soziale Anmeldung konnte nicht abgeschlossen werden. Bitte versuche es erneut.');
        }

        try {
            $profile = $client->fetchProfile($code, $pending['verifier']);
        } catch (\Throwable $exception) {
            $logger->warning('Die externe Profilidentität konnte nicht sicher abgerufen werden.', [
                'provider' => $provider,
                'failure_type' => $exception::class,
            ]);

            return $this->failure('Die soziale Anmeldung konnte nicht bestätigt werden. Bitte verwende eine andere Anmeldemethode.');
        }

        $identities = $entityManager->getRepository(MemberExternalIdentity::class);
        $members = $entityManager->getRepository(MemberAccount::class);
        if (!$identities instanceof MemberExternalIdentityRepository || !$members instanceof MemberAccountRepository) {
            throw new \LogicException('Die Mitgliedsrepositories sind nicht verfügbar.');
        }

        if ('link' === $pending['intent']) {
            return $this->completeLink($entityManager, $identities, $profile, $provider, $pending['member_id'] ?? null, $logger);
        }

        $identity = $identities->findByProviderSubject($provider, $profile->subject);
        if (null !== $identity) {
            $member = $identity->getMember();
            if (!$member->isActive() || !$member->isEmailVerified()) {
                return $this->failure('Die soziale Anmeldung kann für dieses Mitgliedskonto nicht bestätigt werden.');
            }

            try {
                $response = $userAuthenticator->authenticateUser($member, new MemberSocialLoginAuthenticator(), $request);
            } catch (\Throwable $exception) {
                $logger->error('Ein Mitglied konnte über seine verknüpfte externe Identität nicht angemeldet werden.', [
                    'provider' => $provider,
                    'failure_type' => $exception::class,
                ]);

                return $this->failure('Die Anmeldung konnte nicht abgeschlossen werden. Bitte versuche es erneut.');
            }

            return $this->noStore($response ?? $this->redirectToRoute('member_profile'));
        }

        if (!$this->settingsFrom($entityManager)->isMemberRegistrationEnabled()) {
            return $this->failure('Neue Mitgliedskonten sind derzeit nicht verfügbar. Bestehende verknüpfte Konten können sich weiterhin anmelden.');
        }
        if (null !== $profile->email && null !== $members->findOneBy(['email' => $profile->email])) {
            return $this->failure('Für diese E-Mail-Adresse besteht bereits ein Konto. Melde dich an und verknüpfe den Anbieter danach im Profil.');
        }

        $session->set('member_social_registration_pending', [
            'provider' => $provider,
            'subject' => $profile->subject,
            'email' => $profile->email,
            'display_name' => $profile->displayName,
            'created_at' => time(),
        ]);

        return $this->noStore($this->redirectToRoute('member_social_register'));
    }

    #[Route('/sozial/registrieren', name: 'social_register', methods: ['GET', 'POST'])]
    public function registerExternal(
        Request $request,
        EntityManagerInterface $entityManager,
        FacebookOAuthClient $facebook,
        TwitterOAuthClient $twitter,
        MemberSecurityRequestLimiter $securityLimiter,
        MemberSecurityMailService $mailService,
        LoggerInterface $logger,
    ): Response {
        if (!$this->settingsFrom($entityManager)->isMemberRegistrationEnabled()) {
            return $this->noStore(new Response('', Response::HTTP_NOT_FOUND));
        }

        $session = $request->getSession();
        $pending = $session->get('member_social_registration_pending');
        if (!$this->validRegistrationContext($pending)) {
            $session->remove('member_social_registration_pending');

            return $this->failure('Die externe Registrierung ist abgelaufen. Starte die Anmeldung erneut.');
        }
        $client = $this->client($pending['provider'], $facebook, $twitter);
        if (!$this->providerEnabled($pending['provider'], $entityManager) || !$client->isConfigured()) {
            return $this->noStore(new Response('', Response::HTTP_NOT_FOUND));
        }

        $values = [
            'email' => is_string($pending['email'] ?? null) ? $pending['email'] : '',
            'handle' => '',
            'display_name' => $pending['display_name'],
        ];
        if (!$request->isMethod('POST')) {
            return $this->noStore($this->render('member/social_registration.html.twig', [
                'values' => $values,
                'errors' => [],
                'provider' => $pending['provider'],
            ]));
        }
        $formData = $request->request->all();
        $csrfToken = $formData['_token'] ?? '';
        if (!is_string($csrfToken)) {
            return $this->noStore(new Response('', Response::HTTP_BAD_REQUEST));
        }
        if (!$this->isCsrfTokenValid('member_social_register', $csrfToken)) {
            return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
        }

        if ([] !== array_diff(array_keys($formData), ['_token', 'email', 'handle', 'display_name', 'website'])) {
            return $this->noStore(new Response('', Response::HTTP_BAD_REQUEST));
        }
        $rawEmail = $formData['email'] ?? '';
        $rawHandle = $formData['handle'] ?? '';
        $rawDisplayName = $formData['display_name'] ?? '';
        $honeypot = $formData['website'] ?? '';
        if (!is_string($rawEmail) || !is_string($rawHandle) || !is_string($rawDisplayName) || !is_string($honeypot)) {
            return $this->noStore(new Response('', Response::HTTP_BAD_REQUEST));
        }
        $values = [
            'email' => trim($rawEmail),
            'handle' => mb_strtolower(trim($rawHandle), 'UTF-8'),
            'display_name' => trim($rawDisplayName),
        ];
        $errors = [];
        if (strlen($rawEmail) > 1016 || strlen($rawHandle) > 128 || strlen($rawDisplayName) > 320) {
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
        }
        if ([] !== $errors) {
            return $this->noStore($this->render('member/social_registration.html.twig', [
                'values' => $values,
                'errors' => $errors,
                'provider' => $pending['provider'],
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY)));
        }

        if ('' !== trim($honeypot)) {
            $session->remove('member_social_registration_pending');

            return $this->noStore($this->redirectToRoute('member_login'));
        }
        $lastRegistrationAt = $session->get('member_registration_last_at');
        if (is_int($lastRegistrationAt) && time() - $lastRegistrationAt < 60) {
            return $this->noStore($this->render('member/social_registration.html.twig', [
                'values' => $values,
                'errors' => ['Bitte warte kurz, bevor du ein weiteres Konto erstellst.'],
                'provider' => $pending['provider'],
            ], new Response(status: Response::HTTP_TOO_MANY_REQUESTS)));
        }
        $session->set('member_registration_last_at', time());
        $normalizedEmail = mb_strtolower($values['email'], 'UTF-8');
        try {
            if (!$securityLimiter->allow($request, $normalizedEmail)) {
                return $this->noStore($this->render('member/social_registration.html.twig', [
                    'values' => $values,
                    'errors' => ['Bitte warte kurz, bevor du ein weiteres Konto erstellst.'],
                    'provider' => $pending['provider'],
                ], new Response(status: Response::HTTP_TOO_MANY_REQUESTS)));
            }
        } catch (\Throwable $exception) {
            $logger->warning('Eine externe Mitgliedsregistrierung konnte nicht sicher gedrosselt werden.', [
                'failure_type' => $exception::class,
            ]);

            return $this->noStore($this->render('member/social_registration.html.twig', [
                'values' => $values,
                'errors' => ['Die Registrierung ist derzeit nicht verfügbar.'],
                'provider' => $pending['provider'],
            ], new Response(status: Response::HTTP_SERVICE_UNAVAILABLE)));
        }

        $members = $entityManager->getRepository(MemberAccount::class);
        if (!$members instanceof MemberAccountRepository) {
            throw new \LogicException('Das Mitgliedsrepository ist nicht verfügbar.');
        }
        if (null !== $members->findOneBy(['email' => $normalizedEmail])
            || null !== $members->findOneBy(['handle' => $values['handle']])
        ) {
            return $this->noStore($this->render('member/social_registration.html.twig', [
                'values' => $values,
                'errors' => ['Diese Kombination aus E-Mail-Adresse oder Kanalname ist nicht verfügbar.'],
                'provider' => $pending['provider'],
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY)));
        }

        try {
            $member = new MemberAccount($normalizedEmail, $values['handle'], $values['display_name']);
            $member->requireEmailVerification();
            $identity = new MemberExternalIdentity($member, $pending['provider'], $pending['subject']);
            $entityManager->wrapInTransaction(static function (EntityManagerInterface $transactionalEntityManager) use ($member, $identity): void {
                $transactionalEntityManager->persist($member);
                $transactionalEntityManager->persist($identity);
                $transactionalEntityManager->flush();
            });
        } catch (UniqueConstraintViolationException) {
            return $this->noStore($this->render('member/social_registration.html.twig', [
                'values' => $values,
                'errors' => ['Diese Kombination aus E-Mail-Adresse oder Kanalname ist nicht verfügbar.'],
                'provider' => $pending['provider'],
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY)));
        } catch (\Throwable $exception) {
            $logger->warning('Ein externes Mitgliedskonto konnte nicht sicher erstellt werden.', [
                'failure_type' => $exception::class,
            ]);

            return $this->noStore($this->render('member/social_registration.html.twig', [
                'values' => $values,
                'errors' => ['Das Mitgliedskonto konnte nicht erstellt werden. Bitte versuche es erneut.'],
                'provider' => $pending['provider'],
            ], new Response(status: Response::HTTP_SERVICE_UNAVAILABLE)));
        }

        $session->remove('member_social_registration_pending');
        $mailService->send($member, MemberEmailChallengePurpose::EmailVerification);
        $this->addFlash('member_success', 'Bitte bestätige deine E-Mail-Adresse über den Link in deinem Postfach, bevor du dich anmeldest.');

        return $this->noStore($this->redirectToRoute('member_login'));
    }

    #[Route('/sozial/{provider}/trennen', name: 'social_unlink', requirements: ['provider' => 'facebook|twitter'], methods: ['POST'])]
    public function unlink(
        string $provider,
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        $member = $this->getUser();
        if (!$member instanceof MemberAccount || !$member->isActive() || !$member->isEmailVerified()) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('member_social_unlink_'.$provider, $request->request->getString('_token'))) {
            return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
        }
        $identities = $entityManager->getRepository(MemberExternalIdentity::class);
        if (!$identities instanceof MemberExternalIdentityRepository) {
            throw new \LogicException('Das Mitgliedsidentitätsrepository ist nicht verfügbar.');
        }
        $identity = $identities->findForMember($member, $provider);
        if (null === $identity) {
            $this->addFlash('member_error', 'Für dieses externe Konto besteht keine Verknüpfung.');

            return $this->noStore($this->redirectToRoute('member_profile'));
        }
        if (!$member->hasUsablePasswordCredential()) {
            $this->addFlash('member_error', 'Lege zuerst ein lokales Passwort fest, bevor du deine letzte externe Anmeldung entfernst.');

            return $this->noStore($this->redirectToRoute('member_profile'));
        }
        $entityManager->remove($identity);
        $entityManager->flush();
        $this->addFlash('member_success', 'Die externe Verknüpfung wurde entfernt.');

        return $this->noStore($this->redirectToRoute('member_profile'));
    }

    private function client(string $provider, FacebookOAuthClient $facebook, TwitterOAuthClient $twitter): SocialOAuthClientInterface
    {
        return match ($provider) {
            'facebook' => $facebook,
            'twitter' => $twitter,
            default => throw $this->createNotFoundException(),
        };
    }

    private function providerEnabled(string $provider, EntityManagerInterface $entityManager): bool
    {
        $settings = $this->settingsFrom($entityManager);

        return match ($provider) {
            'facebook' => $settings->isFacebookSocialLoginEnabled(),
            'twitter' => $settings->isTwitterSocialLoginEnabled(),
            default => false,
        };
    }

    private function startFlow(
        Request $request,
        SocialOAuthClientInterface $client,
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
            $request->getSession()->set('member_'.$client->provider().'_oauth_pending', [
                'state' => $state,
                'verifier' => $verifier,
                'intent' => $intent,
                'member_id' => $member?->getId(),
                'created_at' => time(),
            ]);

            return $this->noStore($this->redirect($client->authorizationUrl($state, $challenge)));
        } catch (\Throwable $exception) {
            $logger->warning('Der externe Anmeldeablauf konnte nicht sicher gestartet werden.', [
                'provider' => $client->provider(),
                'failure_type' => $exception::class,
            ]);

            return $this->noStore(new Response('', Response::HTTP_SERVICE_UNAVAILABLE));
        }
    }

    private function completeLink(
        EntityManagerInterface $entityManager,
        MemberExternalIdentityRepository $identities,
        SocialOAuthProfile $profile,
        string $provider,
        mixed $expectedMemberId,
        LoggerInterface $logger,
    ): Response {
        $member = $this->getUser();
        if (!$member instanceof MemberAccount
            || !is_string($expectedMemberId)
            || !hash_equals($member->getId(), $expectedMemberId)
            || !$member->isActive()
            || !$member->isEmailVerified()
        ) {
            return $this->failure('Melde dich erneut an und starte die Kontoverknüpfung noch einmal.');
        }

        $existingIdentity = $identities->findByProviderSubject($provider, $profile->subject);
        if (null !== $existingIdentity) {
            $this->addFlash('member_error', $existingIdentity->getMember()->getId() === $member->getId()
                ? 'Dieses externe Konto ist bereits mit deinem Profil verknüpft.'
                : 'Dieses externe Konto kann nicht mit diesem Profil verknüpft werden.');

            return $this->noStore($this->redirectToRoute('member_profile'));
        }
        if (null !== $identities->findForMember($member, $provider)) {
            $this->addFlash('member_error', 'Dein Profil besitzt bereits eine Verknüpfung für diesen Anbieter.');

            return $this->noStore($this->redirectToRoute('member_profile'));
        }

        try {
            $entityManager->persist(new MemberExternalIdentity($member, $provider, $profile->subject));
            $entityManager->flush();
        } catch (\Throwable $exception) {
            $logger->warning('Eine externe Mitgliedsverknüpfung konnte nicht gespeichert werden.', [
                'provider' => $provider,
                'failure_type' => $exception::class,
            ]);
            $this->addFlash('member_error', 'Die externe Verknüpfung konnte nicht gespeichert werden.');

            return $this->noStore($this->redirectToRoute('member_profile'));
        }

        $this->addFlash('member_success', 'Dein externes Konto wurde verknüpft.');

        return $this->noStore($this->redirectToRoute('member_profile'));
    }

    /** @param positive-int $bytes */
    private function oauthToken(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    private function settingsFrom(EntityManagerInterface $entityManager): SiteSettings
    {
        $settings = $entityManager->getRepository(SiteSettings::class)->find(SiteSettings::SINGLETON_ID);

        return $settings instanceof SiteSettings ? $settings : new SiteSettings();
    }

    private function failure(string $message): Response
    {
        $this->addFlash('member_error', $message);

        return $this->noStore($this->redirectToRoute('member_login'));
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    /** @phpstan-assert-if-true array{provider: 'facebook'|'twitter', subject: non-empty-string, email: string|null, display_name: string, created_at: int} $pending */
    private function validRegistrationContext(mixed $pending): bool
    {
        return is_array($pending)
            && in_array($pending['provider'] ?? null, ['facebook', 'twitter'], true)
            && is_string($pending['subject'] ?? null)
            && '' !== $pending['subject']
            && strlen($pending['subject']) <= 255
            && 1 === preg_match('//u', $pending['subject'])
            && 1 !== preg_match('/[\x00-\x1F\x7F]/u', $pending['subject'])
            && is_string($pending['display_name'] ?? null)
            && mb_strlen($pending['display_name'], 'UTF-8') <= 80
            && 1 === preg_match('//u', $pending['display_name'])
            && 1 !== preg_match('/[\x00-\x1F\x7F]/u', $pending['display_name'])
            && (null === ($pending['email'] ?? null) || (is_string($pending['email']) && false !== filter_var($pending['email'], FILTER_VALIDATE_EMAIL)))
            && is_int($pending['created_at'] ?? null)
            && time() - $pending['created_at'] <= 600;
    }
}
