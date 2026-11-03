<?php

declare(strict_types=1);

namespace App\Module\Installer\Controller;

use App\Entity\AdminUser;
use App\Module\Installer\Service\InstallerDatabaseConnectionProbe;
use App\Module\Installer\Service\InstallerDatabaseProbeResult;
use App\Platform\Security\RateLimitKeyFactory;
use App\Repository\AdminUserRepository;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/install/database', name: 'installer_database_')]
final class InstallerDatabaseController extends AbstractController
{
    private const string CSRF_TOKEN_ID = 'installer_database_probe';
    private const array ALLOWED_INPUT_FIELDS = ['token'];

    public function __construct(
        private readonly InstallerDatabaseConnectionProbe $connectionProbe,
        private readonly ManagerRegistry $managerRegistry,
        private readonly RateLimitKeyFactory $rateLimitKeys,
        #[Autowire(service: 'limiter.installer_database_probe')]
        private readonly RateLimiterFactory $databaseProbeLimiter,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request, LoggerInterface $logger): Response
    {
        $attemptAllowed = $this->consumeAttempt($request, $logger);
        if (null === $attemptAllowed) {
            return $this->renderProbe(
                null,
                ['form' => 'Die Datenbankprüfung ist vorübergehend nicht verfügbar.'],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        if (!$attemptAllowed) {
            $response = $this->renderProbe(
                null,
                ['form' => 'Die Datenbankprüfung ist derzeit begrenzt. Versuchen Sie es später erneut.'],
                Response::HTTP_TOO_MANY_REQUESTS,
            );
            $response->headers->set('Retry-After', '3600');

            return $response;
        }

        if ($request->isMethod(Request::METHOD_POST)) {
            $input = $request->request->all();
            if ([] !== array_diff(array_keys($input), self::ALLOWED_INPUT_FIELDS)) {
                return $this->renderProbe(
                    null,
                    ['form' => 'Die Anfrage enthält nicht zulässige Felder.'],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }

            $token = $input['token'] ?? null;
            if (!is_string($token) || !$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $token)) {
                return $this->privateResponse(new Response('', Response::HTTP_FORBIDDEN));
            }
        }

        $probe = $this->connectionProbe->check();
        if (null !== $probe->failureType) {
            $logger->warning('Die Datenbank-Installationsprüfung meldet einen Fehler.', [
                'failure_type' => $probe->failureType,
            ]);
        }

        if (!$probe->connectionAvailable) {
            return $this->renderProbe($probe, [], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (!$probe->requiredTablesAvailable) {
            $status = null === $probe->failureType ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE;

            return $this->renderProbe(
                $probe,
                ['form' => 'Die Verbindung antwortet, aber die erforderlichen Anwendungstabellen fehlen. Führen Sie die Migrationen auf dem Server aus und prüfen Sie erneut.'],
                $status,
            );
        }

        $installationComplete = $this->installationIsComplete($logger);
        if (null === $installationComplete) {
            return $this->renderProbe(
                $probe,
                ['form' => 'Die Datenbankprüfung ist vorübergehend nicht verfügbar.'],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        if ($installationComplete) {
            return $this->notFoundResponse();
        }

        if ($request->isMethod(Request::METHOD_POST)) {
            $this->addFlash('success', 'Die Datenbankverbindung wurde erfolgreich geprüft.');

            return $this->privateResponse($this->redirectToRoute(
                'installer_security_profile_index',
                [],
                Response::HTTP_SEE_OTHER,
            ));
        }

        return $this->renderProbe($probe);
    }

    private function consumeAttempt(Request $request, LoggerInterface $logger): ?bool
    {
        try {
            return $this->databaseProbeLimiter
                ->create($this->rateLimitKeys->forIp($request, 'installer-database-probe'))
                ->consume(1)
                ->isAccepted();
        } catch (\Throwable $exception) {
            $logger->error('Der Datenbank-Prüfungslimiter ist nicht verfügbar.', [
                'failure_type' => $exception::class,
            ]);

            return null;
        }
    }

    /** @phpstan-impure */
    private function installationIsComplete(LoggerInterface $logger): ?bool
    {
        try {
            $repository = $this->managerRegistry->getRepository(AdminUser::class);
            if (!$repository instanceof AdminUserRepository) {
                throw new \RuntimeException('Das Administratorkonto-Repository ist nicht verfügbar.');
            }

            return 0 < $repository->countAdministratorsForRoleManagement();
        } catch (\Throwable $exception) {
            $logger->error('Der Installationsstatus konnte nicht sicher gelesen werden.', [
                'failure_type' => $exception::class,
            ]);

            return null;
        }
    }

    /**
     * @param array<string, string> $errors
     */
    private function renderProbe(
        ?InstallerDatabaseProbeResult $probe,
        array $errors = [],
        int $status = Response::HTTP_OK,
    ): Response {
        return $this->privateResponse($this->render('installer/database.html.twig', [
            'probe' => $probe,
            'errors' => $errors,
        ], new Response(status: $status)));
    }

    private function notFoundResponse(): Response
    {
        return $this->privateResponse(new Response('', Response::HTTP_NOT_FOUND));
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
