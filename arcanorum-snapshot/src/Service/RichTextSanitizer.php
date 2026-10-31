<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Twig\Markup;

final readonly class RichTextSanitizer
{
    private const int MAX_INPUT_BYTES = 1_000_000;

    private HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = new HtmlSanitizerConfig();
        foreach ([
            'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'del', 'ins', 'ul', 'ol', 'li',
            'h2', 'h3', 'h4', 'blockquote', 'pre', 'code', 'hr', 'div', 'span',
        ] as $element) {
            $config = $config->allowElement($element);
        }
        $config = $config
            ->allowElement('a', ['href', 'title'])
            ->allowElement('img', ['src', 'alt'])
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowMediaSchemes([])
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->forceAttribute('a', 'rel', 'nofollow noopener noreferrer')
            ->withMaxInputLength(self::MAX_INPUT_BYTES);

        $this->sanitizer = new HtmlSanitizer($config);
    }

    public function sanitize(string $html): string
    {
        if (strlen($html) > self::MAX_INPUT_BYTES || 1 !== preg_match('//u', $html)) {
            throw new \InvalidArgumentException('Der Inhalt ist zu groß oder enthält ungültiges UTF-8.');
        }

        $sanitized = $this->sanitizer->sanitize($html);

        return preg_replace_callback(
            '~<img\b[^>]*>~i',
            static function (array $match): string {
                if (1 !== preg_match('~\bsrc\s*=\s*(["\'])/content-inline-images/([a-f0-9]{64})\1~i', $match[0], $source)) {
                    return '';
                }

                $alt = '';
                if (1 === preg_match('~\balt\s*=\s*(["\'])(.*?)\1~is', $match[0], $alternativeText)) {
                    $alt = $alternativeText[2];
                }

                return '<img src="/content-inline-images/'.strtolower($source[2]).'" alt="'.$alt.'">';
            },
            $sanitized,
        ) ?? $sanitized;
    }

    /** @return list<string> */
    public function inlineImageIds(string $html): array
    {
        $sanitized = $this->sanitize($html);
        $count = preg_match_all('~<img\s+src="/content-inline-images/([a-f0-9]{64})"\s+alt="[^"]*">~', $sanitized, $matches);
        if (false === $count || 0 === $count) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    public function render(string $html): Markup
    {
        try {
            $safeHtml = $this->sanitize($html);
        } catch (\InvalidArgumentException) {
            $safeHtml = '';
        }

        return new Markup($safeHtml, 'UTF-8');
    }

    /** @param list<string> $allowedImageIds */
    public function renderWithAllowedInlineImageIds(string $html, array $allowedImageIds): Markup
    {
        $safeHtml = (string) $this->render($html);
        $allowed = array_fill_keys($allowedImageIds, true);
        $safeHtml = preg_replace_callback(
            '~<img\b[^>]*>~i',
            static function (array $match) use ($allowed): string {
                if (1 !== preg_match('~\bsrc="/content-inline-images/([a-f0-9]{64})"~', $match[0], $source)
                    || !isset($allowed[$source[1]])
                ) {
                    return '';
                }

                return $match[0];
            },
            $safeHtml,
        ) ?? '';

        return new Markup($safeHtml, 'UTF-8');
    }
}
