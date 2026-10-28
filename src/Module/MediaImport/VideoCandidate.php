<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

final readonly class VideoCandidate
{
    public string $title;
    public string $description;

    public function __construct(
        public MediaProvider $provider,
        public string $externalId,
        string $title,
        string $description,
    ) {
        if (!$provider->acceptsVideoId($externalId)) {
            throw new \InvalidArgumentException('The provider video identifier is invalid.');
        }

        $this->title = self::plainText($title, 200, true);
        $this->description = self::plainText($description, 4000, false);
    }

    public function canonicalUrl(): string
    {
        return $this->provider->videoPageUrl($this->externalId);
    }

    private static function plainText(string $value, int $maximumLength, bool $required): string
    {
        if (strlen($value) > 65536 || 1 !== preg_match('//u', $value)) {
            throw new \InvalidArgumentException('The provider text is invalid.');
        }

        $decoded = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $withoutControls = preg_replace('/[\p{C}]/u', ' ', $decoded);
        $normalized = is_string($withoutControls) ? preg_replace('/\s+/u', ' ', $withoutControls) : false;
        if (!is_string($normalized)) {
            throw new \InvalidArgumentException('The provider text is invalid.');
        }

        $normalized = trim($normalized);
        if ($required && '' === $normalized) {
            throw new \InvalidArgumentException('A video title is required.');
        }

        $limited = iconv_substr($normalized, 0, $maximumLength, 'UTF-8');
        if (!is_string($limited)) {
            throw new \InvalidArgumentException('The provider text is invalid.');
        }

        return $limited;
    }
}
