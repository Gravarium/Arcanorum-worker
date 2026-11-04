<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\HostingSecuritySelection;
use App\Platform\Hosting\HostingProfileDetector;
use App\Platform\Hosting\HostingSecurityFeatureCatalog;
use App\Platform\Hosting\HostingSecurityFeatureChoice;
use App\Platform\Hosting\HostingSecurityFeatureSelectionOverview;
use App\Repository\AdminUserRepository;
use App\Repository\HostingSecuritySelectionRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class HostingSecurityFeatureSelectionManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private HostingSecuritySelectionRepository $selections,
        private AdminUserRepository $adminUsers,
        private HostingProfileDetector $hostingProfile,
        private HostingSecurityFeatureCatalog $catalog,
    ) {
    }

    /** @return list<HostingSecurityFeatureChoice> */
    public function choices(): array
    {
        return $this->overview()->optionalFeatures;
    }

    public function overview(): HostingSecurityFeatureSelectionOverview
    {
        $selection = $this->selections->findCurrent();
        if (!$selection instanceof HostingSecuritySelection) {
            throw new \RuntimeException('Die Host-Sicherheitsauswahl ist nicht initialisiert.');
        }

        $report = $this->hostingProfile->detect();

        return new HostingSecurityFeatureSelectionOverview(
            $report,
            $this->catalog->choices($report, $selection->getSelectedFeatureIds()),
        );
    }

    public function replaceSelection(mixed $requestedFeatureIds): void
    {
        $this->replaceSelectionTransactionally($requestedFeatureIds, false);
    }

    /**
     * Replace the pre-installation selection only if no administrator was created
     * while the request was in flight. The installation mutex serializes this
     * check with the first administrator transaction.
     */
    public function replaceSelectionDuringInstallation(mixed $requestedFeatureIds): bool
    {
        return $this->replaceSelectionTransactionally($requestedFeatureIds, true);
    }

    private function replaceSelectionTransactionally(mixed $requestedFeatureIds, bool $requireUninstalled): bool
    {
        if (!is_array($requestedFeatureIds)) {
            throw new \InvalidArgumentException('Die Auswahl muss eine Liste sein.');
        }

        $report = $this->hostingProfile->detect();
        return $this->entityManager->getConnection()->transactional(function () use ($requestedFeatureIds, $report, $requireUninstalled): bool {
            $selection = $this->selections->findCurrentForUpdate();
            if (!$selection instanceof HostingSecuritySelection) {
                throw new \RuntimeException('Die Host-Sicherheitsauswahl ist nicht initialisiert.');
            }

            if ($requireUninstalled && 0 < $this->adminUsers->countAdministratorsForRoleManagement()) {
                return false;
            }

            $validated = $this->catalog->replaceSelection(
                $requestedFeatureIds,
                $selection->getSelectedFeatureIds(),
                $report,
            );
            $selection->replaceSelectedFeatureIds($validated);
            $this->entityManager->flush();

            return true;
        });
    }
}
