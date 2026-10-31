<?php

declare(strict_types=1);

namespace App\Service;

final readonly class AdminSystemEvent
{
    public function __construct(
        public AdminSystemEventSeverity $severity,
        public string $message,
        public \DateTimeImmutable $observedAt,
    ) {
        if ('' === trim($message)) {
            throw new \InvalidArgumentException('Ein Systemereignis benötigt eine Beschreibung.');
        }
    }
}
