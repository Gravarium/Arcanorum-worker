<?php

declare(strict_types=1);

namespace App\Module\Admin\Controller;

use App\Service\AdminPhpRuntimeSummary;
use App\Service\SystemCapabilitySummary;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class AdminSystemController extends AbstractController
{
    public function __construct(
        private readonly SystemCapabilitySummary $systemCapabilities,
        private readonly AdminPhpRuntimeSummary $phpRuntime,
    ) {
    }

    #[Route('/admin/system', name: 'admin_system_index', methods: ['GET'])]
    public function index(): Response
    {
        $system = $this->systemCapabilities->current();
        $php = $this->phpRuntime->current();

        $response = $this->render('admin/system/index.html.twig', [
            'notices' => [...$system['notices'], ...$php['notices']],
            'checks' => $system['checks'],
            ...$php,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
