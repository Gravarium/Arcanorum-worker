<?php

declare(strict_types=1);

namespace App\Module\Admin\Controller;

use App\Platform\Hosting\HostingSecurityFeatureSelectionOverview;
use App\Service\HostingSecurityFeatureSelectionManager;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/system/security', name: 'admin_hosting_security_')]
final class AdminHostingSecurityController extends AbstractController
{
    private const array ALLOWED_INPUT_FIELDS = ['selected_features'];

    public function __construct(
        private readonly HostingSecurityFeatureSelectionManager $selectionManager,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(LoggerInterface $logger): Response
    {
        return $this->renderSavedSelection($logger);
    }

    #[Route('', name: 'update', methods: ['POST'])]
    public function update(Request $request, LoggerInterface $logger): Response
    {
        $input = $request->request->all();
        $submittedToken = $input['token'] ?? null;
        if (!is_string($submittedToken) || !$this->isCsrfTokenValid('admin_hosting_security', $submittedToken)) {
            return $this->privateResponse(new Response('', Response::HTTP_FORBIDDEN));
        }

        unset($input['token']);
        if ([] !== array_diff(array_keys($input), self::ALLOWED_INPUT_FIELDS)) {
            return $this->renderSavedSelection(
                $logger,
                ['form' => 'Die Anfrage enthält unbekannte Einstellungsfelder.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $this->selectionManager->replaceSelection($input['selected_features'] ?? []);
        } catch (\InvalidArgumentException) {
            return $this->renderSavedSelection(
                $logger,
                ['form' => 'Bitte prüfen Sie die Auswahl und den aktuellen Hostingnachweis.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        } catch (DbalException|\RuntimeException $exception) {
            $logger->error('Die Host-Sicherheitsauswahl konnte nicht gespeichert werden.', [
                'failure_type' => $exception::class,
            ]);

            return $this->privateResponse(new Response(
                'Die Auswahl konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.',
                Response::HTTP_SERVICE_UNAVAILABLE,
            ));
        }

        $this->addFlash('success', 'Die Host-Sicherheitsauswahl wurde gespeichert.');

        return $this->privateResponse($this->redirectToRoute('admin_hosting_security_index'));
    }

    /**
     * @param array<string, string> $errors
     */
    private function renderSavedSelection(
        LoggerInterface $logger,
        array $errors = [],
        int $status = Response::HTTP_OK,
    ): Response {
        try {
            $overview = $this->selectionManager->overview();
        } catch (DbalException|\RuntimeException $exception) {
            $logger->error('Die Host-Sicherheitsauswahl konnte nicht gelesen werden.', [
                'failure_type' => $exception::class,
            ]);

            return $this->privateResponse(new Response(
                'Die Auswahl konnte nicht geladen werden. Bitte versuchen Sie es erneut.',
                Response::HTTP_SERVICE_UNAVAILABLE,
            ));
        }

        return $this->renderSelection($overview, $errors, $status);
    }

    /**
     * @param array<string, string> $errors
     */
    private function renderSelection(
        HostingSecurityFeatureSelectionOverview $overview,
        array $errors = [],
        int $status = Response::HTTP_OK,
    ): Response {
        return $this->privateResponse($this->render('admin/system/security.html.twig', [
            'overview' => $overview,
            'errors' => $errors,
        ], new Response(status: $status)));
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
