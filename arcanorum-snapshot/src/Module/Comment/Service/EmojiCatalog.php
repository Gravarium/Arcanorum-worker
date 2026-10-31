<?php

declare(strict_types=1);

namespace App\Module\Comment\Service;

final class EmojiCatalog
{
    /**
     * @var list<array{key: string, label: string}>
     */
    private const array CATEGORY_METADATA = [
        ['key' => 'people', 'label' => 'Menschen'],
        ['key' => 'activity', 'label' => 'Emoji-Aktivität'],
        ['key' => 'nature', 'label' => 'Natur'],
        ['key' => 'food', 'label' => 'Essen'],
        ['key' => 'travel', 'label' => 'Reisen'],
        ['key' => 'objects', 'label' => 'Objekte'],
        ['key' => 'symbols', 'label' => 'Symbole'],
        ['key' => 'flags', 'label' => 'Flaggen'],
    ];

    /**
     * @var list<array{key: string, items: list<array{value: string, label: string, search: string}>}>|null
     */
    private static ?array $cachedCategories = null;

    /**
     * @return list<array{key: string, label: string}>
     */
    public function categoryMetadata(): array
    {
        return self::CATEGORY_METADATA;
    }

    /**
     * @return list<array{key: string, label: string, items: list<array{value: string, label: string, search: string}>}>
     */
    public function categories(): array
    {
        $itemsByCategory = [];
        foreach (self::loadCategories() as $category) {
            $itemsByCategory[$category['key']] = $category['items'];
        }

        return array_map(
            static fn (array $metadata): array => [
                'key' => $metadata['key'],
                'label' => $metadata['label'],
                'items' => $itemsByCategory[$metadata['key']],
            ],
            self::CATEGORY_METADATA,
        );
    }

    /**
     * @return list<array{key: string, items: list<array{value: string, label: string, search: string}>}>
     */
    public function publicCategories(): array
    {
        return self::loadCategories();
    }

    /**
     * @return list<array{key: string, items: list<array{value: string, label: string, search: string}>}>
     */
    private static function loadCategories(): array
    {
        if (null !== self::$cachedCategories) {
            return self::$cachedCategories;
        }

        $path = __DIR__.'/emoji-catalog.json';
        $json = file_get_contents($path);
        if (false === $json) {
            throw new \RuntimeException('The bundled Unicode emoji catalog could not be read.');
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('The bundled Unicode emoji catalog is invalid.', 0, $exception);
        }

        $rawCategories = is_array($data) ? ($data['categories'] ?? null) : null;
        if (!is_array($rawCategories) || !array_is_list($rawCategories) || count($rawCategories) !== count(self::CATEGORY_METADATA)) {
            throw new \RuntimeException('The bundled Unicode emoji catalog has an invalid category list.');
        }

        $expectedKeys = array_column(self::CATEGORY_METADATA, 'key');
        $categories = [];
        $itemCount = 0;
        foreach ($rawCategories as $index => $rawCategory) {
            if (!is_array($rawCategory)
                || ($rawCategory['key'] ?? null) !== $expectedKeys[$index]
                || !is_array($rawCategory['items'] ?? null)
                || !array_is_list($rawCategory['items'])
            ) {
                throw new \RuntimeException('The bundled Unicode emoji catalog contains an invalid category.');
            }

            $items = [];
            foreach ($rawCategory['items'] as $rawItem) {
                if (!is_array($rawItem)
                    || !is_string($rawItem['value'] ?? null)
                    || '' === $rawItem['value']
                    || !is_string($rawItem['label'] ?? null)
                    || '' === $rawItem['label']
                    || !is_string($rawItem['search'] ?? null)
                ) {
                    throw new \RuntimeException('The bundled Unicode emoji catalog contains an invalid item.');
                }

                $items[] = [
                    'value' => $rawItem['value'],
                    'label' => $rawItem['label'],
                    'search' => $rawItem['search'],
                ];
                ++$itemCount;
            }

            $categories[] = ['key' => $expectedKeys[$index], 'items' => $items];
        }

        if (3963 !== $itemCount) {
            throw new \RuntimeException('The bundled Unicode emoji catalog is incomplete.');
        }

        self::$cachedCategories = $categories;

        return self::$cachedCategories;
    }
}
