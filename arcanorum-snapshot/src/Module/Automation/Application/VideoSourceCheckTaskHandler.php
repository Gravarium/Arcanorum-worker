<?php

declare(strict_types=1);

namespace App\Module\Automation\Application;

use App\Entity\ExternalVideoSourceStatus;
use App\Entity\ScheduledTask;
use App\Entity\ScheduledTaskType;
use App\Service\VideoProviderImportException;
use App\Service\VideoProviderImportService;
use Doctrine\ORM\EntityManagerInterface;

final readonly class VideoSourceCheckTaskHandler implements ScheduledTaskHandlerInterface
{
    private const int MAX_SOURCES_PER_RUN = 5;
    private const int RECHECK_AFTER_HOURS = 24;

    public function __construct(
        private VideoProviderImportService $providerImport,
        private ExternalVideoSourceStore $videos,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function type(): ScheduledTaskType
    {
        return ScheduledTaskType::VideoSourceCheck;
    }

    public function execute(ScheduledTask $task, \DateTimeImmutable $now): string
    {
        if (!$task->isSystemTask() || 'video-source-health-check' !== $task->getTaskKey()) {
            throw new \LogicException('Der feste Quellenprüfer wurde manipuliert.');
        }

        $cutoff = $now->modify(sprintf('-%d hours', self::RECHECK_AFTER_HOURS));
        $candidates = $this->videos->findExternalSourcesNeedingCheck($cutoff, self::MAX_SOURCES_PER_RUN);
        if ([] === $candidates) {
            return 'Keine fälligen externen Videoquellen gefunden.';
        }

        $changes = [];
        $unavailable = 0;
        $unsupported = 0;
        $failed = 0;
        foreach ($candidates as $video) {
            try {
                $available = $this->providerImport->checkSourceAvailability($video->getSourceUrl());
            } catch (VideoProviderImportException) {
                $video->markExternalSourceCheckFailed($now);
                ++$failed;
                continue;
            }

            $status = match ($available) {
                true => ExternalVideoSourceStatus::Available,
                false => ExternalVideoSourceStatus::Unavailable,
                null => ExternalVideoSourceStatus::Unsupported,
            };
            $changes[] = [$video, $status];
            if (ExternalVideoSourceStatus::Unavailable === $status) {
                ++$unavailable;
            } elseif (ExternalVideoSourceStatus::Unsupported === $status) {
                ++$unsupported;
            }
        }

        foreach ($changes as [$video, $status]) {
            $video->markExternalSourceStatus($status, $now);
        }
        $this->entityManager->flush();
        if ($failed > 0) {
            throw new VideoProviderImportException('Mindestens eine Providerprüfung konnte nicht abgeschlossen werden.');
        }

        return sprintf(
            '%d Quellen geprüft; %d nicht verfügbar und zur Moderation vorgemerkt; %d nicht unterstützte Quellen übersprungen.',
            count($changes),
            $unavailable,
            $unsupported,
        );
    }
}
