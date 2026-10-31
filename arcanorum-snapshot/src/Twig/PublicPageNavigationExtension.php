<?php

declare(strict_types=1);

namespace App\Twig;

use App\Repository\StaticPageRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class PublicPageNavigationExtension extends AbstractExtension
{
    public function __construct(private readonly StaticPageRepository $pages)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('public_menu_pages', $this->pages->findPublishedForMenu(...))];
    }
}
