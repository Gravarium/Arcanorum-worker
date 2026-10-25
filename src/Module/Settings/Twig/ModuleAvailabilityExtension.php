<?php

declare(strict_types=1);

namespace App\Module\Settings\Twig;

use App\Module\Settings\Service\ModuleAvailability;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ModuleAvailabilityExtension extends AbstractExtension
{
    public function __construct(private readonly ModuleAvailability $modules)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('module_enabled', $this->modules->isEnabled(...))];
    }
}
