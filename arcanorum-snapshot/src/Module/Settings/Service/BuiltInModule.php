<?php

declare(strict_types=1);

namespace App\Module\Settings\Service;

use App\Entity\FeatureModule;
use App\Platform\Hosting\HostingCapability;

final readonly class BuiltInModule
{
    /**
     * @param list<HostingCapability> $requiredCapabilities
     * @param list<class-string>      $controllerClasses
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public string $namespace,
        public bool $configurable,
        public ?FeatureModule $featureModule,
        public array $requiredCapabilities,
        public array $controllerClasses = [],
    ) {
        if (1 !== preg_match('/\A[a-z][a-z0-9_]*\z/', $id)
            || '' === trim($name)
            || '' === trim($description)
            || 1 !== preg_match('/\A[A-Z][A-Za-z0-9]*\z/', $namespace)
            || $configurable !== (null !== $featureModule)
        ) {
            throw new \InvalidArgumentException('Das integrierte Modul ist nicht vollständig oder mehrdeutig deklariert.');
        }

        if (count($requiredCapabilities) !== count(array_unique($requiredCapabilities, SORT_REGULAR))) {
            throw new \InvalidArgumentException('Ein Modul darf dieselbe erforderliche Fähigkeit nicht mehrfach deklarieren.');
        }

        if (count($controllerClasses) !== count(array_unique($controllerClasses))) {
            throw new \InvalidArgumentException('Ein Modul darf dieselbe Controllerklasse nicht mehrfach deklarieren.');
        }

        foreach ($controllerClasses as $controllerClass) {
            if (!str_starts_with($controllerClass, 'App\\Module\\')) {
                throw new \InvalidArgumentException('Modulrouten müssen aus einem verwalteten App-Modul stammen.');
            }
        }
    }
}
