<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\ContentCategory;
use App\Repository\ContentInlineImageRepository;
use App\Service\RichTextSanitizer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class RichTextExtension extends AbstractExtension
{
    public function __construct(
        private readonly RichTextSanitizer $sanitizer,
        private readonly ContentInlineImageRepository $images,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('rich_text', $this->sanitizer->render(...), ['is_safe' => ['html']]),
            new TwigFilter('rich_text_for_category', $this->renderForCategory(...), ['is_safe' => ['html']]),
        ];
    }

    public function renderForCategory(string $description, ContentCategory $category): \Twig\Markup
    {
        try {
            $ids = $this->sanitizer->inlineImageIds($description);
        } catch (\InvalidArgumentException) {
            return new \Twig\Markup('', 'UTF-8');
        }

        return $this->sanitizer->renderWithAllowedInlineImageIds(
            $description,
            $this->images->ownedIdsForCategory($category->getId(), $ids),
        );
    }
}
