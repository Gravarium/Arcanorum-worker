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
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowRelativeLinks()
            ->forceAttribute('a', 'rel', 'nofollow noopener noreferrer')
            ->withMaxInputLength(self::MAX_INPUT_BYTES);

        $this->sanitizer = new HtmlSanitizer($config);
    }

    public function sanitize(string $html): string
    {
        if (strlen($html) > self::MAX_INPUT_BYTES || 1 !== preg_match('//u', $html)) {
            throw new \InvalidArgumentException('Der Inhalt ist zu groß oder enthält ungültiges UTF-8.');
        }

        return $this->sanitizer->sanitize($html);
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
}
