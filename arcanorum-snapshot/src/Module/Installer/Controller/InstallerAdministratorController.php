<?php

declare(strict_types=1);

namespace App\Module\Installer\Controller;

use App\Entity\AdminRole;
use App\Entity\AdminUser;
use App\Platform\Security\AdminMfaProvisioningResult;
use App\Platform\Security\AdminMfaProvisioningService;
use App\Platform\Security\AdminMfaSecretCipher;
use App\Platform\Security\Base32Encoder;
use App\Platform\Security\RateLimitKeyFactory;
use App\Platform\Security\RuntimeAttestationActionGuard;
use App\Platform\Security\RuntimeAttestationIncidentReporterInterface;
use App\Platform\Security\RuntimeAttestationReportProviderInterface;
use App\Platform\Security\RuntimeAttestationStatus;
use App\Platform\Security\RuntimeAttestationUnavailable;
use App\Platform\Security\TotpVerifier;
use App\Repository\AdminUserRepository;
use App\Repository\HostingSecuritySelectionRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/install/administrator', name: 'installer_administrator_')]
final class InstallerAdministratorController extends AbstractController
{
    private const string PENDING_SESSION_KEY = '_installer_first_admin_pending';
    private const string EXPIRED_SETUP_SESSION_KEY = '_installer_first_admin_expired';
    private const string RECOVERY_CODES_SESSION_KEY = '_installer_first_admin_recovery_codes';
    private const int SETUP_TTL_SECONDS = 1200;
    private const int PASSWORD_MIN_LENGTH = 16;
    private const int PASSWORD_MAX_LENGTH = 1024;

    private const array CREDENTIAL_FIELDS = ['email', 'password', 'password_confirmation'];
    private const array MFA_FIELDS = ['code'];
    private const array REQUIRED_CONTROL_IDS = [
        'INV-AUTH-001',
        'INV-APP-INTEGRITY-001',
        'INV-WEBSERVER-CONFIG-001',
        'INV-INPUT-OUTPUT-001',
    ];

    public function __construct(
        private readonly AdminUserRepository $adminUsers,
        private readonly HostingSecuritySelectionRepository $hostingSelections,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly AdminMfaProvisioningService $mfaProvisioner,
        private readonly AdminMfaSecretCipher $secretCipher,
        private readonly TotpVerifier $totpVerifier,
        private readonly RuntimeAttestationReportProviderInterface $runtimeAttestationReportProvider,
        private readonly RuntimeAttestationActionGuard $runtimeAttestationActionGuard,
        private readonly RuntimeAttestationIncidentReporterInterface $incidentReporter,
        #[Autowire('%env(bool:RUNTIME_ATTESTATION_REQUIRED)%')]
        private readonly bool $runtimeAttestationRequired,
        private readonly RateLimitKeyFactory $rateLimitKeys,
        #[Autowire(service: 'limiter.installer_first_admin')]
        private readonly RateLimiterFactory $firstAdminLimiter,
    ) {
    }

    #[Route('', name: 'credentials', methods: ['GET', 'POST'])]
    public function credentials(Request $request, LoggerInterface $logger): Response
    {
        $isComplete = $this->installationIsComplete($logger);
        if (null === $isComplete) {
            return $this->unavailableResponse();
        }
        if ($isComplete) {
            $request->getSession()->remove(self::PENDING_SESSION_KEY);

            return $this->notFoundResponse();
        }
        $session = $request->getSession();
        $hadPendingSetup = $session->has(self::PENDING_SESSION_KEY);
        $expiredSetup = true === $session->get(self::EXPIRED_SETUP_SESSION_KEY);
        $session->remove(self::EXPIRED_SETUP_SESSION_KEY);
        $pending = $this->readPendingSetup($request);
        if ($request->isMethod(Request::METHOD_GET)) {
            if (null !== $pending) {
                return $this->redirectPrivate('installer_administrator_mfa');
            }

            if ($hadPendingSetup || $expiredSetup) {
                return $this->renderStep('credentials', [], ['form' => 'Die Installationssitzung ist abgelaufen. Starten Sie die Einrichtung erneut.']);
            }

            return $this->renderStep('credentials');
        }

        $input = $request->request->all();
        $token = $input['token'] ?? null;
        if (!is_string($token) || !$this->isCsrfTokenValid('installer_first_admin_credentials', $token)) {
            return $this->privateResponse(new Response('', Response::HTTP_FORBIDDEN));
        }
        unset($input['token']);
        if ([] !== array_diff(array_keys($input), self::CREDENTIAL_FIELDS)) {
            return $this->renderStep('credentials', [], ['form' => 'Die Kontodaten sind ungültig oder das Konto kann nicht angelegt werden.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $attemptAllowed = $this->consumeAttempt($request, $logger);
        if (null === $attemptAllowed) {
            return $this->unavailableResponse();
        }
        if (!$attemptAllowed) {
            return $this->renderStep('credentials', [], ['form' => 'Zu viele Versuche. Bitte warten Sie vor dem nächsten Versuch.'], Response::HTTP_TOO_MANY_REQUESTS);
        }
        if (!$this->runtimeAttestationAllowsAdministratorCreation($logger)) {
            return $this->unavailableResponse();
        }

        $emailInput = $input['email'] ?? null;
        $password = $input['password'] ?? null;
        $confirmation = $input['password_confirmation'] ?? null;
        if (!is_string($emailInput)
            || !mb_check_encoding($emailInput, 'UTF-8')
            || !is_string($password)
            || !is_string($confirmation)
            || !mb_check_encoding($password, 'UTF-8')
            || !mb_check_encoding($confirmation, 'UTF-8')
        ) {
            return $this->renderStep('credentials', [], ['form' => 'Die Kontodaten sind ungültig oder das Konto kann nicht angelegt werden.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $email = mb_strtolower(trim($emailInput), 'UTF-8');
        if (false === filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email, 'UTF-8') > 180) {
            return $this->renderStep('credentials', [], ['form' => 'Bitte geben Sie eine gültige E-Mail-Adresse mit höchstens 180 Zeichen ein.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $passwordLength = mb_strlen($password, 'UTF-8');
        if ($passwordLength < self::PASSWORD_MIN_LENGTH) {
            return $this->renderStep('credentials', [], ['form' => 'Das Passwort muss mindestens 16 Zeichen lang sein.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($passwordLength > self::PASSWORD_MAX_LENGTH) {
            return $this->renderStep('credentials', [], ['form' => 'Die Kontodaten sind ungültig oder das Konto kann nicht angelegt werden.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!hash_equals($password, $confirmation)) {
            return $this->renderStep('credentials', [], ['form' => 'Die Passwörter stimmen nicht überein.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = new AdminUser($email);
        $user->setRoles([AdminRole::Administrator->value]);
        $passwordHash = $this->passwordHasher->hashPassword($user, $password);
        if (!$this->runtimeAttestationAllowsAdministratorCreation($logger)) {
            return $this->unavailableResponse();
        }

        try {
            $secret = random_bytes(20);
            $encryptedSecret = $this->secretCipher->encrypt($secret);
            if (!$session->migrate(true)) {
                throw new \RuntimeException('Die Installationssitzung konnte nicht erneuert werden.');
            }
            $session->set(self::PENDING_SESSION_KEY, [
                'version' => 1,
                'issued_at' => time(),
                'email' => $email,
                'password_hash' => $passwordHash,
                'secret_ciphertext' => $encryptedSecret,
            ]);
        } catch (\Throwable $exception) {
            $logger->error('Der Erstadministratoreinrichtungsstatus konnte nicht sicher vorbereitet werden.', [
                'failure_type' => $exception::class,
            ]);

            return $this->unavailableResponse();
        }

        return $this->redirectPrivate('installer_administrator_mfa');
    }

    #[Route('/mfa', name: 'mfa', methods: ['GET', 'POST'])]
    public function mfa(Request $request, LoggerInterface $logger): Response
    {
        $isComplete = $this->installationIsComplete($logger);
        if (null === $isComplete) {
            return $this->unavailableResponse();
        }
        if ($isComplete) {
            $request->getSession()->remove(self::PENDING_SESSION_KEY);

            return $this->notFoundResponse();
        }

        $session = $request->getSession();
        $hadPendingSetup = $session->has(self::PENDING_SESSION_KEY);
        $pending = $this->readPendingSetup($request);
        if (null === $pending) {
            if ($hadPendingSetup) {
                $session->set(self::EXPIRED_SETUP_SESSION_KEY, true);
            }

            return $this->redirectPrivate('installer_administrator_credentials');
        }

        if ($request->isMethod(Request::METHOD_GET)) {
            return $this->renderMfaStep($pending);
        }

        $input = $request->request->all();
        $token = $input['token'] ?? null;
        if (!is_string($token) || !$this->isCsrfTokenValid('installer_first_admin_mfa', $token)) {
            return $this->privateResponse(new Response('', Response::HTTP_FORBIDDEN));
        }
        unset($input['token']);

        if ([] !== array_diff(array_keys($input), self::MFA_FIELDS)) {
            return $this->renderMfaStep(
                $pending,
                ['form' => 'Der Bestätigungscode ist ungültig. Prüfen Sie die Gerätezeit und versuchen Sie es erneut.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $attemptAllowed = $this->consumeAttempt($request, $logger);
        if (null === $attemptAllowed) {
            return $this->unavailableResponse();
        }
        if (!$attemptAllowed) {
            return $this->renderMfaStep(
                $pending,
                ['form' => 'Zu viele Versuche. Bitte warten Sie vor dem nächsten Versuch.'],
                Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        $code = $input['code'] ?? null;
        if (!is_string($code) || null === $this->totpVerifier->matchingCounter($pending['secret'], $code, time())) {
            return $this->renderMfaStep(
                $pending,
                ['form' => 'Der Bestätigungscode ist ungültig. Prüfen Sie die Gerätezeit und versuchen Sie es erneut.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (!$this->runtimeAttestationAllowsAdministratorCreation($logger)) {
            return $this->unavailableResponse();
        }

        try {
            $provisioned = $this->entityManager->getConnection()->transactional(
                function (Connection $connection) use ($pending): ?AdminMfaProvisioningResult {
                    if (null === $this->hostingSelections->findCurrentForUpdate()) {
                        throw new \RuntimeException('Die Installationssperre ist nicht initialisiert.');
                    }

                    if (0 < $this->adminUsers->countAdministratorsForRoleManagement()
                        || null !== $this->adminUsers->findOneByEmail($pending['email'])
                    ) {
                        return null;
                    }

                    $user = new AdminUser($pending['email']);
                    $user->setRoles([AdminRole::Administrator->value]);
                    $user->setPassword($pending['password_hash']);

                    return $this->mfaProvisioner->provisionWithConfirmedSecret($user, $pending['secret']);
                },
            );
        } catch (\Throwable $exception) {
            $logger->error('Das erste Administratorkonto konnte nicht sicher provisioniert werden.', [
                'failure_type' => $exception::class,
            ]);

            return $this->unavailableResponse();
        }

        if (!$provisioned instanceof AdminMfaProvisioningResult) {
            $request->getSession()->remove(self::PENDING_SESSION_KEY);

            return $this->notFoundResponse();
        }

        $session = $request->getSession();
        $session->remove(self::PENDING_SESSION_KEY);
        $session->set(self::RECOVERY_CODES_SESSION_KEY, $provisioned->recoveryCodes);

        return $this->redirectPrivate('installer_administrator_recovery');
    }

    #[Route('/recovery', name: 'recovery', methods: ['GET'])]
    public function recovery(Request $request): Response
    {
        $session = $request->getSession();
        $codes = $session->get(self::RECOVERY_CODES_SESSION_KEY);
        $session->remove(self::RECOVERY_CODES_SESSION_KEY);
        if (!is_array($codes) || !array_is_list($codes) || 10 !== count($codes)) {
            return $this->notFoundResponse();
        }

        foreach ($codes as $code) {
            if (!is_string($code) || 1 !== preg_match('/\A[A-Z2-7]{4}(?:-[A-Z2-7]{4}){5}-[A-Z2-7]{2}\z/', $code)) {
                return $this->notFoundResponse();
            }
        }

        return $this->renderStep('recovery', ['recovery_codes' => $codes]);
    }

    /**
     * @return array{email: string, password_hash: string, secret: string}|null
     */
    private function readPendingSetup(Request $request): ?array
    {
        $session = $request->getSession();
        $pending = $session->get(self::PENDING_SESSION_KEY);
        if (!is_array($pending)) {
            return null;
        }

        $issuedAt = $pending['issued_at'] ?? null;
        $email = $pending['email'] ?? null;
        $passwordHash = $pending['password_hash'] ?? null;
        $ciphertext = $pending['secret_ciphertext'] ?? null;
        if (1 !== ($pending['version'] ?? null)
            || !is_int($issuedAt)
            || $issuedAt > time() + 60
            || time() - $issuedAt > self::SETUP_TTL_SECONDS
            || !is_string($email)
            || 180 < mb_strlen($email, 'UTF-8')
            || false === filter_var($email, FILTER_VALIDATE_EMAIL)
            || !is_string($passwordHash)
            || '' === $passwordHash
            || 255 < strlen($passwordHash)
            || !is_string($ciphertext)
        ) {
            $session->remove(self::PENDING_SESSION_KEY);

            return null;
        }

        try {
            $secret = $this->secretCipher->decrypt($ciphertext);
        } catch (\Throwable) {
            $session->remove(self::PENDING_SESSION_KEY);

            return null;
        }

        if (20 !== strlen($secret)) {
            $session->remove(self::PENDING_SESSION_KEY);

            return null;
        }

        return ['email' => $email, 'password_hash' => $passwordHash, 'secret' => $secret];
    }

    /** @phpstan-impure */
    private function runtimeAttestationAllowsAdministratorCreation(LoggerInterface $logger): bool
    {
        if (!$this->runtimeAttestationRequired) {
            return true;
        }

        try {
            $report = $this->runtimeAttestationReportProvider->verifyCurrent();
            $this->runtimeAttestationActionGuard->requireVerified($report, ...self::REQUIRED_CONTROL_IDS);

            return true;
        } catch (\Throwable $failure) {
            $status = $failure instanceof RuntimeAttestationUnavailable
                ? $failure->status
                : RuntimeAttestationStatus::Unknown;

            try {
                $this->incidentReporter->report('app:admin:create', $status, self::REQUIRED_CONTROL_IDS);
            } catch (\Throwable) {
                // Missing incident reporting does not relax the setup guard.
            }

            $logger->warning('Der Erstadministrator blieb wegen fehlender Laufzeitnachweise gesperrt.', [
                'status' => $status->value,
                'failure_type' => $failure::class,
            ]);

            return false;
        }
    }

    private function consumeAttempt(Request $request, LoggerInterface $logger): ?bool
    {
        try {
            return $this->firstAdminLimiter
                ->create($this->rateLimitKeys->forIp($request, 'installer-first-admin'))
                ->consume(1)
                ->isAccepted();
        } catch (\Throwable $exception) {
            $logger->error('Der Installationsversuchslimiter ist nicht verfügbar.', [
                'failure_type' => $exception::class,
            ]);

            return null;
        }
    }

    /** @phpstan-impure */
    private function installationIsComplete(LoggerInterface $logger): ?bool
    {
        try {
            return 0 < $this->adminUsers->countAdministratorsForRoleManagement();
        } catch (DbalException|\RuntimeException $exception) {
            $logger->error('Der Installationsstatus konnte nicht sicher gelesen werden.', [
                'failure_type' => $exception::class,
            ]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $errors
     */
    private function renderStep(string $step, array $data = [], array $errors = [], int $status = Response::HTTP_OK): Response
    {
        return $this->privateResponse($this->render('installer/administrator.html.twig', [
            'step' => $step,
            'data' => $data,
            'errors' => $errors,
        ], new Response(status: $status)));
    }

    /**
     * @param array{email: string, password_hash: string, secret: string} $pending
     * @param array<string, string> $errors
     */
    private function renderMfaStep(array $pending, array $errors = [], int $status = Response::HTTP_OK): Response
    {
        return $this->renderStep('mfa', [
            'totp_secret' => Base32Encoder::encode($pending['secret']),
        ], $errors, $status);
    }

    private function redirectPrivate(string $route): Response
    {
        return $this->privateResponse($this->redirectToRoute($route, [], Response::HTTP_SEE_OTHER));
    }

    private function notFoundResponse(): Response
    {
        return $this->privateResponse(new Response('', Response::HTTP_NOT_FOUND));
    }

    private function unavailableResponse(): Response
    {
        return $this->privateResponse(new Response('Der Installationsschritt ist vorübergehend nicht verfügbar.', Response::HTTP_SERVICE_UNAVAILABLE));
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
