<?php

declare(strict_types=1);

namespace App\Module\Automation\Application;

use App\Entity\ScheduledTask;
use App\Entity\ScheduledTaskType;
use App\Module\Feed\Service\SitemapDocumentService;

final readonly class SitemapRefreshTaskHandler implements ScheduledTaskHandlerInterface
{
    public function __construct(private SitemapDocumentService $sitemaps)
    {
    }

    public function type(): ScheduledTaskType
    {
        return ScheduledTaskType::SitemapRefresh;
    }

    public function execute(ScheduledTask $task, \DateTimeImmutable $now): string
    {
        if ([] !== $task->getConfiguration()) {
            throw new \LogicException('Der gespeicherte Sitemapauftrag enthält unbekannte Einstellungen.');
        }

        $this->sitemaps->refresh();

        return 'Reguläre Sitemap und Video-Sitemap wurden aktualisiert.';
    }
}
