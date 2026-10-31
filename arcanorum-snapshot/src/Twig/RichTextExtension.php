<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\RichTextSanitizer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class RichTextExtension extends AbstractExtension
{
    public function __construct(private readonly RichTextSanitizer $sanitizer)
    {
    }

    public function getFilters(): array
    {
        return [new TwigFilter('rich_text', $this->sanitizer->render(...), ['is_safe' => ['html']])];
    }
}
