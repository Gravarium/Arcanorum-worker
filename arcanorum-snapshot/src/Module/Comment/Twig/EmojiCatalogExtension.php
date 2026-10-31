<?php

declare(strict_types=1);

namespace App\Module\Comment\Twig;

use App\Module\Comment\Service\EmojiCatalog;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class EmojiCatalogExtension extends AbstractExtension
{
    public function __construct(private readonly EmojiCatalog $catalog)
    {
    }

    /** @return list<TwigFunction> */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('emoji_catalog', $this->catalog->categories(...)),
            new TwigFunction('emoji_catalog_categories', $this->catalog->categoryMetadata(...)),
        ];
    }
}
