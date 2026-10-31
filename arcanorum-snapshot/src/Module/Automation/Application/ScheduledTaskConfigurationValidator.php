<?php

declare(strict_types=1);

namespace App\Module\Automation\Application;

use App\Entity\ScheduledTaskType;
use App\Service\VideoProviderImportService;

final readonly class ScheduledTaskConfigurationValidator
{
    /** @param array<string, mixed> $configuration
     *
     * @return array<string, mixed>
     */
    public function normalize(ScheduledTaskType $type, array $configuration): array
    {
        if (ScheduledTaskType::SitemapRefresh === $type) {
            if ([] !== $configuration) {
                throw new \InvalidArgumentException('Der Sitemapauftrag akzeptiert keine zusätzlichen Einstellungen.');
            }

            return [];
        }

        if (ScheduledTaskType::ProviderImport !== $type) {
            throw new \InvalidArgumentException('Dieser Auftragstyp kann nicht manuell konfiguriert werden.');
        }

        $allowedKeys = ['provider', 'mode', 'query', 'channel_id', 'category', 'exclude_terms'];
        if ([] !== array_diff(array_keys($configuration), $allowedKeys)) {
            throw new \InvalidArgumentException('Die Aufgabe enthält unbekannte Konfigurationsfelder.');
        }

        $provider = self::stringValue($configuration, 'provider');
        $mode = self::stringValue($configuration, 'mode');
        $query = trim(self::stringValue($configuration, 'query'));
        $channelId = trim(self::stringValue($configuration, 'channel_id'));
        $categoryValue = $configuration['category'] ?? '';
        if (!is_string($categoryValue)) {
            throw new \InvalidArgumentException('Die Zielkategorie ist ungültig.');
        }
        $category = trim($categoryValue);
        $terms = $configuration['exclude_terms'] ?? [];

        if (!VideoProviderImportService::isSupportedProvider($provider)) {
            throw new \InvalidArgumentException('Wähle einen unterstützten Videoanbieter.');
        }
        if (!in_array($mode, ['search', 'channel'], true)) {
            throw new \InvalidArgumentException('Wähle eine gültige Suche oder ein Nutzerabo.');
        }
        if ('search' === $mode && (!VideoProviderImportService::isValidSearchTerm($query) || '' !== $channelId)) {
            throw new \InvalidArgumentException('Gib einen gültigen Suchbegriff ohne zusätzliche Kanal-ID ein.');
        }
        if ('channel' === $mode && (!VideoProviderImportService::isValidChannelId($provider, $channelId) || '' !== $query)) {
            throw new \InvalidArgumentException(sprintf('Gib eine gültige %s ohne zusätzlichen Suchbegriff ein.', VideoProviderImportService::channelLabel($provider)));
        }
        if (!mb_check_encoding($category, 'UTF-8') || mb_strlen($category, 'UTF-8') > 100 || self::containsControlCharacters($category)) {
            throw new \InvalidArgumentException('Die Zielkategorie ist ungültig.');
        }
        if (!is_array($terms) || !array_is_list($terms) || count($terms) > 20) {
            throw new \InvalidArgumentException('Es sind höchstens 20 Ausschlussbegriffe erlaubt.');
        }

        $normalizedTerms = [];
        $seen = [];
        foreach ($terms as $term) {
            if (!is_string($term)) {
                throw new \InvalidArgumentException('Ausschlussbegriffe müssen Textwerte sein.');
            }
            $term = trim($term);
            if ('' === $term || !mb_check_encoding($term, 'UTF-8') || mb_strlen($term, 'UTF-8') > 80 || self::containsControlCharacters($term)) {
                throw new \InvalidArgumentException('Jeder Ausschlussbegriff muss 1 bis 80 gültige Zeichen enthalten.');
            }
            $key = mb_strtolower($term, 'UTF-8');
            if (!isset($seen[$key])) {
                $normalizedTerms[] = $term;
                $seen[$key] = true;
            }
        }

        return [
            'provider' => $provider,
            'mode' => $mode,
            'query' => $query,
            'channel_id' => $channelId,
            'category' => '' === $category ? null : $category,
            'exclude_terms' => $normalizedTerms,
        ];
    }

    /** @param array<string, mixed> $values */
    private static function stringValue(array $values, string $key): string
    {
        $value = $values[$key] ?? '';
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Die Aufgabenkonfiguration enthält ungültige Feldtypen.');
        }

        return $value;
    }

    private static function containsControlCharacters(string $value): bool
    {
        return 1 === preg_match('/[\x00-\x1F\x7F]/u', $value);
    }
}
