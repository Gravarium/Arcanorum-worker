<?php

declare(strict_types=1);

namespace App\Module\Settings\Service;

use App\Entity\SiteSettings;
use App\Platform\Hosting\CapabilityEvidence;
use App\Platform\Hosting\CapabilityStatus;
use App\Platform\Hosting\HostingCapability;
use App\Platform\Hosting\HostingCapabilityReport;
use App\Platform\Hosting\HostingProfileDetector;
use App\Service\SiteSettingsProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class ModuleAvailability
{
    private const string REQUEST_ATTRIBUTE = '_arcanorum_module_availability';

    /** @var list<class-string> */
    private const array OWNER_SCOPED_CONTROLLERS = [
        \App\Module\Content\Controller\AdminContentInlineImageController::class,
        \App\Module\Content\Controller\ContentInlineImageController::class,
    ];

    public function __construct(
        private BuiltInModuleCatalog $catalog,
        private HostingProfileDetector $hostingProfile,
        private SiteSettingsProvider $settings,
        private RequestStack $requestStack,
        private LoggerInterface $logger,
    ) {
    }

    public function isEnabled(string $moduleId): bool
    {
        $module = $this->catalog->find($moduleId);
        if (null === $module) {
            return false;
        }

        if (!$module->configurable) {
            return true;
        }

        $feature = $module->featureModule;
        if (null === $feature) {
            return false;
        }

        $context = $this->context();
        if (!$context['settings']->isFeatureModuleEnabled($feature)) {
            return false;
        }

        return $this->capabilitiesVerified($module->requiredCapabilities, $context['report']);
    }

    public function controllerIsEnabled(mixed $controller): bool
    {
        if (!is_string($controller) || '' === $controller) {
            return true;
        }

        $separator = strpos($controller, '::');
        $controllerClass = false === $separator ? $controller : substr($controller, 0, $separator);
        if (!$this->catalog->isModuleNamespace($controllerClass)) {
            return true;
        }

        // These shared media routes enforce the owning news/pages module inside their actions.
        if (in_array($controllerClass, self::OWNER_SCOPED_CONTROLLERS, true)) {
            return true;
        }

        $module = $this->catalog->forController($controllerClass);

        return null !== $module && $this->isEnabled($module->id);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, string>
     */
    public function unavailableEnableErrors(array $input): array
    {
        $errors = [];
        $context = $this->context();
        $report = $context['report'];

        foreach ($this->catalog->all() as $module) {
            $feature = $module->featureModule;
            if (!$module->configurable || null === $feature || $context['settings']->isFeatureModuleEnabled($feature)) {
                continue;
            }

            $value = $input[$feature->inputName()] ?? null;
            if (!in_array($value, [true, 1, '1'], true)) {
                continue;
            }

            $missing = $this->unverifiedCapabilities($module->requiredCapabilities, $report);
            if ([] !== $missing) {
                $errors[$feature->inputName()] = sprintf(
                    'Das Modul kann erst aktiviert werden, wenn folgende Hostingfähigkeiten aktuell nachgewiesen sind: %s.',
                    implode(', ', $missing),
                );
            }
        }

        return $errors;
    }

    /** @return list<array{id: string, name: string, description: string, configurable: bool, configured_enabled: ?bool, enabled: bool, capability_ready: bool, can_enable: bool, required_capabilities: list<array{id: string, label: string, status: string, status_class: string}>}> */
    public function configuration(): array
    {
        $context = $this->context();
        $result = [];
        foreach ($this->catalog->all() as $module) {
            $capabilities = [];
            foreach ($module->requiredCapabilities as $capability) {
                $status = $context['report']->statusFor($capability);
                $capabilities[] = [
                    'id' => $capability->value,
                    'label' => $capability->label(),
                    'status' => $status->label(),
                    'status_class' => $status->cssClass(),
                ];
            }

            $feature = $module->featureModule;
            $configuredEnabled = null === $feature ? null : $context['settings']->isFeatureModuleEnabled($feature);
            $capabilityReady = $this->capabilitiesVerified($module->requiredCapabilities, $context['report']);
            $result[] = [
                'id' => $module->id,
                'name' => $module->name,
                'description' => $module->description,
                'configurable' => $module->configurable,
                'configured_enabled' => $configuredEnabled,
                'enabled' => !$module->configurable || (true === $configuredEnabled && $capabilityReady),
                'capability_ready' => $capabilityReady,
                'can_enable' => !$module->configurable || $capabilityReady || true === $configuredEnabled,
                'required_capabilities' => $capabilities,
            ];
        }

        return $result;
    }

    /** @return array{settings: SiteSettings, report: HostingCapabilityReport} */
    private function context(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $cached = $request?->attributes->get(self::REQUEST_ATTRIBUTE);
        if (is_array($cached)
            && ($cached['settings'] ?? null) instanceof SiteSettings
            && ($cached['report'] ?? null) instanceof HostingCapabilityReport
        ) {
            return $cached;
        }

        $context = [
            'settings' => $this->settings->current(),
            'report' => $this->detectCapabilities(),
        ];
        $request?->attributes->set(self::REQUEST_ATTRIBUTE, $context);

        return $context;
    }

    private function detectCapabilities(): HostingCapabilityReport
    {
        try {
            return $this->hostingProfile->detect();
        } catch (\Throwable $exception) {
            $checkedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $checks = [];
            foreach (HostingCapability::cases() as $capability) {
                $checks[$capability->value] = new CapabilityEvidence(
                    $capability,
                    CapabilityStatus::Unknown,
                    self::class,
                    'Der Fähigkeitsnachweis ist wegen eines Prüfffehlers nicht verfügbar.',
                    $checkedAt,
                );
            }

            $this->logger->warning('Hostingfähigkeiten konnten für die Modulsteuerung nicht geprüft werden.', [
                'failure_type' => $exception::class,
            ]);

            return new HostingCapabilityReport($checks);
        }
    }

    /** @param list<HostingCapability> $capabilities */
    private function capabilitiesVerified(array $capabilities, HostingCapabilityReport $report): bool
    {
        return [] === $this->unverifiedCapabilities($capabilities, $report);
    }

    /** @param list<HostingCapability> $capabilities
     * @return list<string>
     */
    private function unverifiedCapabilities(array $capabilities, HostingCapabilityReport $report): array
    {
        $missing = [];
        foreach ($capabilities as $capability) {
            if (CapabilityStatus::Verified !== $report->statusFor($capability)) {
                $missing[] = $capability->label();
            }
        }

        return $missing;
    }
}
