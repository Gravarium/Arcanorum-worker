<?php

declare(strict_types=1);

namespace App\Content;

final class CustomFieldSet
{
    public const int MAX_FIELDS = 30;
    private const int MAX_KEY_BYTES = 255;
    private const int MAX_VALUE_BYTES = 4096;
    private const int MAX_TOTAL_BYTES = 30000;

    /**
     * @return list<array{key: string, value: string}>|null
     */
    public static function normalize(mixed $fields): ?array
    {
        if (!is_array($fields) || !array_is_list($fields) || count($fields) > self::MAX_FIELDS) {
            return null;
        }

        $normalized = [];
        $seenKeys = [];
        $totalBytes = 0;
        foreach ($fields as $field) {
            if (!is_array($field)
                || [] !== array_diff(array_keys($field), ['key', 'value'])
                || !isset($field['key'], $field['value'])
                || !is_string($field['key'])
                || !is_string($field['value'])
            ) {
                return null;
            }

            $key = trim($field['key']);
            $value = $field['value'];
            if ('' === $key && '' === trim($value)) {
                continue;
            }

            if ('' === $key
                || strlen($key) > self::MAX_KEY_BYTES
                || strlen($value) > self::MAX_VALUE_BYTES
                || 1 !== preg_match('//u', $key)
                || 1 !== preg_match('//u', $value)
                || str_starts_with($key, '_')
                || 1 === preg_match('/[\x00-\x1F\x7F]/u', $key)
                || 1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value)
            ) {
                return null;
            }

            $normalizedKey = mb_strtolower($key, 'UTF-8');
            if (isset($seenKeys[$normalizedKey])) {
                return null;
            }
            $seenKeys[$normalizedKey] = true;
            $totalBytes += strlen($key) + strlen($value);
            if ($totalBytes > self::MAX_TOTAL_BYTES) {
                return null;
            }

            $normalized[] = ['key' => $key, 'value' => $value];
        }

        return $normalized;
    }
}
