<?php

declare(strict_types=1);

namespace App\Module\Advertising\Domain;

final readonly class SafeHtml
{
    private const int MAXIMUM_BYTES = 50000;
    private const array ALLOWED_TAGS = ['div', 'span', 'p', 'strong', 'em', 'b', 'i', 'br', 'ul', 'ol', 'li', 'img'];
    private const array REMOVE_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template', 'form', 'video', 'audio', 'source'];

    private function __construct(public string $html)
    {
    }

    public static function sanitize(string $untrustedHtml): self
    {
        if (strlen($untrustedHtml) > self::MAXIMUM_BYTES) {
            throw new \InvalidArgumentException('HTML banner exceeds the 50000 byte limit.');
        }
        if (!mb_check_encoding($untrustedHtml, 'UTF-8')) {
            throw new \InvalidArgumentException('HTML banner must be valid UTF-8.');
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $previousErrorMode = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<!doctype html><html><head><meta charset="utf-8"></head><body><div data-advertising-root="1">'.$untrustedHtml.'</div></body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }

        if (!$loaded) {
            throw new \InvalidArgumentException('HTML banner could not be parsed.');
        }

        $body = $document->getElementsByTagName('body')->item(0);
        $root = $body instanceof \DOMElement ? $body->firstElementChild : null;
        if (!$root instanceof \DOMElement) {
            throw new \InvalidArgumentException('HTML banner could not be isolated.');
        }

        self::sanitizeChildren($root);
        $safe = '';
        foreach ($root->childNodes as $child) {
            $serialized = $document->saveHTML($child);
            if (false !== $serialized) {
                $safe .= $serialized;
            }
        }

        return new self($safe);
    }

    private static function sanitizeChildren(\DOMNode $parent): void
    {
        for ($node = $parent->firstChild; null !== $node;) {
            $next = $node->nextSibling;
            if (!$node instanceof \DOMElement) {
                $node = $next;
                continue;
            }

            $tag = strtolower($node->tagName);
            if (in_array($tag, self::REMOVE_WITH_CONTENT, true)) {
                $parent->removeChild($node);
                $node = $next;
                continue;
            }

            self::sanitizeChildren($node);
            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                while ($node->firstChild instanceof \DOMNode) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);
                $node = $next;
                continue;
            }

            self::sanitizeAttributes($node, $tag);
            $node = $next;
        }
    }

    private static function sanitizeAttributes(\DOMElement $element, string $tag): void
    {
        if ('img' !== $tag) {
            while ($element->attributes->length > 0) {
                $attribute = $element->attributes->item(0);
                if ($attribute instanceof \DOMAttr) {
                    $element->removeAttributeNode($attribute);
                }
            }

            return;
        }

        $src = $element->getAttribute('src');
        try {
            $safePath = SafeAssetPath::fromString($src)->path;
        } catch (\InvalidArgumentException) {
            $element->parentNode?->removeChild($element);

            return;
        }

        $alt = mb_substr(trim($element->getAttribute('alt')), 0, 160);
        $width = self::boundedDimension($element->getAttribute('width'));
        $height = self::boundedDimension($element->getAttribute('height'));
        while ($element->attributes->length > 0) {
            $attribute = $element->attributes->item(0);
            if ($attribute instanceof \DOMAttr) {
                $element->removeAttributeNode($attribute);
            }
        }
        $element->setAttribute('src', $safePath);
        $element->setAttribute('alt', $alt);
        if (null !== $width) {
            $element->setAttribute('width', (string) $width);
        }
        if (null !== $height) {
            $element->setAttribute('height', (string) $height);
        }
    }

    private static function boundedDimension(string $value): ?int
    {
        if (1 !== preg_match('/\A[0-9]{1,4}\z/D', $value)) {
            return null;
        }

        $dimension = (int) $value;

        return $dimension >= 1 && $dimension <= 2048 ? $dimension : null;
    }
}
