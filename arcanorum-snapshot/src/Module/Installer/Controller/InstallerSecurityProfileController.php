<?php

declare(strict_types=1);

namespace App\Module\Installer\Controller;

use App\Platform\Hosting\HostingSecurityFeatureSelectionOverview;
use App\Repository\AdminUserRepository;
use App\Service\HostingSecurityFeatureSelectionManager;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/install/security-profile', name: 'installer_security_profile_')]
final class InstallerSecurityProfileController extends AbstractController
{
    private const array ALLOWED_INPUT_FIELDS = ['selected_features'];

    public function __construct(
        private readonly AdminUserRepository $adminUsers,
        private readonly HostingSecurityFeatureSelectionManager $selectionManager,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(LoggerInterface $logger): Response
    {
        try {
            if ($this->installationIsComplete()) {
                return $this->privateResponse(new Response('', Response::HTTP_NOT_FOUND));
            }

            $overview = $this->selectionManager->overview();
        } catch (DbalException|\RuntimeException $exception) {
            $this->logSetupFailure($logger, $exception);

            return $this->unavailableResponse();
        }

        return $this->renderOverview($overview);
    }

    #[Route('', name: 'update', methods: ['POST'])]
    public function update(Request $request, LoggerInterface $logger): Response
    {
        try {
            if ($this->installationIsComplete()) {
                return $this->privateResponse(new Response('', Response::HTTP_NOT_FOUND));
            }
        } catch (DbalException|\RuntimeException $exception) {
            $this->logSetupFailure($logger, $exception);

            return $this->unavailableResponse();
        }

        $input = $request->request->all();
        $submittedToken = $input['token'] ?? null;
        if (!is_string($submittedToken) || !$this->isCsrfTokenValid('installer_hosting_security', $submittedToken)) {
            return $this->privateResponse(new Response('', Response::HTTP_FORBIDDEN));
        }

        unset($input['token']);
        try {
            $overview = $this->selectionManager->overview();
        } catch (DbalException|\RuntimeException $exception) {
            $this->logSetupFailure($logger, $exception);

            return $this->unavailableResponse();
        }

        if ([] !== array_diff(array_keys($input), self::ALLOWED_INPUT_FIELDS)) {
            return $this->renderOverview(
                $overview,
                ['form' => 'Die Anfrage enthält unbekannte Einstellungsfelder.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            if ($this->installationIsComplete()) {
                return $this->privateResponse(new Response('', Response::HTTP_NOT_FOUND));
            }

            $this->selectionManager->replaceSelection($input['selected_features'] ?? []);
        } catch (\InvalidArgumentException) {
            try {
                $overview = $this->selectionManager->overview();
            } catch (DbalException|\RuntimeException $exception) {
                $this->logSetupFailure($logger, $exception);

                return $this->unavailableResponse();
            }

            return $this->renderOverview(
                $overview,
                ['form' => 'Bitte prüfen Sie die Auswahl und den aktuellen Hostingnachweis.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        } catch (DbalException|\RuntimeException $exception) {
            $this->logSetupFailure($logger, $exception);

            return $this->unavailableResponse();
        }

        $this->addFlash('success', 'Die Host-Sicherheitsauswahl wurde gespeichert.');

        return $this->privateResponse($this->redirectToRoute('installer_security_profile_index'));
    }

    /** @phpstan-impure */
    private function installationIsComplete(): bool
    {
        return 0 < $this->adminUsers->countAdministratorsForRoleManagement();
    }

    /**
     * @param array<string, string> $errors
     */
    private function renderOverview(
        HostingSecurityFeatureSelectionOverview $overview,
        array $errors = [],
        int $status = Response::HTTP_OK,
    ): Response {
        return $this->privateResponse($this->render('installer/security_profile.html.twig', [
            'overview' => $overview,
            'errors' => $errors,
        ], new Response(status: $status)));
    }

    private function unavailableResponse(): Response
    {
        return $this->privateResponse(new Response(
            'Die Hosting-Prüfung ist derzeit nicht verfügbar.',
            Response::HTTP_SERVICE_UNAVAILABLE,
        ));
    }

    private function logSetupFailure(LoggerInterface $logger, \Throwable $exception): void
    {
        $logger->error('Der Installationsschritt für die Hosting-Sicherheitsauswahl ist fehlgeschlagen.', [
            'failure_type' => $exception::class,
        ]);
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
