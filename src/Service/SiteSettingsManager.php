<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SiteSettings;
use App\Repository\SiteSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SiteSettingsManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SiteSettingsRepository $settings,
    ) {
    }

    /** @param array<string, mixed> $input
     * @return array<string, string> field name to safe user-facing error
     */
    public function update(array $input): array
    {
        $settings = $this->settings->findCurrent();
        $isNew = null === $settings;
        $settings ??= new SiteSettings();

        $errors = $settings->updateFromInput($input);
        if ([] !== $errors) {
            return $errors;
        }

        if ($isNew) {
            $this->entityManager->persist($settings);
        }

        $this->entityManager->flush();

        return [];
    }

    /** @param array<string, mixed> $input
     * @return array<string, string> field name to safe user-facing error
     */
    public function updateFeatureModules(array $input): array
    {
        $settings = $this->settings->findCurrent();
        $isNew = null === $settings;
        $settings ??= new SiteSettings();

        $errors = $settings->updateFeatureModules($input);
        if ([] !== $errors) {
            return $errors;
        }

        if ($isNew) {
            $this->entityManager->persist($settings);
        }

        $this->entityManager->flush();

        return [];
    }

    /** @param array<string, mixed> $input
     * @return array<string, string> field name to safe user-facing error
     */
    public function updateContentOptions(array $input): array
    {
        $settings = $this->settings->findCurrent();
        $isNew = null === $settings;
        $settings ??= new SiteSettings();

        $errors = $settings->updateContentOptions($input);
        if ([] !== $errors) {
            return $errors;
        }

        if ($isNew) {
            $this->entityManager->persist($settings);
        }

        $this->entityManager->flush();

        return [];
    }

    /** @param array<string, mixed> $input
     * @return array<string, string> field name to safe user-facing error
     */
    public function updateEmailSettings(array $input): array
    {
        $settings = $this->settings->findCurrent();
        $isNew = null === $settings;
        $settings ??= new SiteSettings();

        $errors = $settings->updateEmailSettings($input);
        if ([] !== $errors) {
            return $errors;
        }

        if ($isNew) {
            $this->entityManager->persist($settings);
        }

        $this->entityManager->flush();

        return [];
    }

    /** @param array<string, mixed> $input
     * @return array<string, string> field name to safe user-facing error
     */
    public function updateAppearance(array $input): array
    {
        $settings = $this->settings->findCurrent();
        $isNew = null === $settings;
        $settings ??= new SiteSettings();

        $errors = $settings->updateAppearance($input);
        if ([] !== $errors) {
            return $errors;
        }

        if ($isNew) {
            $this->entityManager->persist($settings);
        }

        $this->entityManager->flush();

        return [];
    }
}
