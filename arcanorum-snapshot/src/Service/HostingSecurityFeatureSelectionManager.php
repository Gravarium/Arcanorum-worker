<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\HostingSecuritySelection;
use App\Platform\Hosting\HostingProfileDetector;
use App\Platform\Hosting\HostingSecurityFeatureCatalog;
use App\Platform\Hosting\HostingSecurityFeatureChoice;
use App\Repository\HostingSecuritySelectionRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class HostingSecurityFeatureSelectionManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private HostingSecuritySelectionRepository $selections,
        private HostingProfileDetector $hostingProfile,
        private HostingSecurityFeatureCatalog $catalog,
    ) {
    }

    /** @return list<HostingSecurityFeatureChoice> */
    public function choices(): array
    {
        $selection = $this->selections->findCurrent();
        if (!$selection instanceof HostingSecuritySelection) {
            throw new \RuntimeException('Die Host-Sicherheitsauswahl ist nicht initialisiert.');
        }

        return $this->catalog->choices(
            $this->hostingProfile->detect(),
            $selection->getSelectedFeatureIds(),
        );
    }

    public function replaceSelection(mixed $requestedFeatureIds): void
    {
        if (!is_array($requestedFeatureIds)) {
            throw new \InvalidArgumentException('Die Auswahl muss eine Liste sein.');
        }

        $report = $this->hostingProfile->detect();
        $this->entityManager->getConnection()->transactional(function () use ($requestedFeatureIds, $report): void {
            $selection = $this->selections->findCurrentForUpdate();
            if (!$selection instanceof HostingSecuritySelection) {
                throw new \RuntimeException('Die Host-Sicherheitsauswahl ist nicht initialisiert.');
            }

            $validated = $this->catalog->replaceSelection(
                $requestedFeatureIds,
                $selection->getSelectedFeatureIds(),
                $report,
            );
            $selection->replaceSelectedFeatureIds($validated);
            $this->entityManager->flush();
        });
    }
}
