<?php

declare(strict_types=1);

namespace App\Entity;

use App\Platform\Hosting\HostingSecurityFeature;
use App\Repository\HostingSecuritySelectionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HostingSecuritySelectionRepository::class)]
#[ORM\Table(name: 'hosting_security_selection')]
final class HostingSecuritySelection
{
    public const int SINGLETON_ID = 1;

    #[ORM\Id]
    #[ORM\Column(type: Types::SMALLINT)]
    private int $id = self::SINGLETON_ID;

    /** @var list<string> */
    #[ORM\Column(name: 'selected_feature_ids', type: Types::JSON)]
    private array $selectedFeatureIds = [];

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): int
    {
        return $this->id;
    }

    /** @return list<string> */
    public function getSelectedFeatureIds(): array
    {
        return $this->selectedFeatureIds;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @param array<array-key, mixed> $featureIds */
    public function replaceSelectedFeatureIds(array $featureIds): void
    {
        if (!array_is_list($featureIds)) {
            throw new \InvalidArgumentException('Die Auswahl muss eine Liste sein.');
        }

        $validated = [];
        foreach ($featureIds as $featureId) {
            if (!is_string($featureId)) {
                throw new \InvalidArgumentException('Die Auswahl enthält eine ungültige Funktion.');
            }

            $feature = HostingSecurityFeature::tryFrom($featureId);
            if (null === $feature || in_array($feature->value, $validated, true)) {
                throw new \InvalidArgumentException('Die Auswahl enthält eine ungültige oder doppelte Funktion.');
            }

            $validated[] = $feature->value;
        }

        $this->selectedFeatureIds = $validated;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
