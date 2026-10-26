<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Domain;

final readonly class ScheduleFrequency
{
    public const int MINIMUM_INTERVAL_SECONDS = 60;
    public const int MAXIMUM_INTERVAL_SECONDS = 2_592_000;

    private function __construct(public int $intervalSeconds, public string $timezone)
    {
    }

    public static function fromInput(int $intervalSeconds, string $timezone): self
    {
        if ($intervalSeconds < self::MINIMUM_INTERVAL_SECONDS || $intervalSeconds > self::MAXIMUM_INTERVAL_SECONDS) {
            throw new \InvalidArgumentException('Task intervals must be between 60 seconds and 30 days.');
        }

        if ('UTC' !== $timezone && (!str_contains($timezone, '/') || !in_array($timezone, timezone_identifiers_list(\DateTimeZone::ALL), true))) {
            throw new \InvalidArgumentException('Task timezone must be a recognized IANA timezone.');
        }

        return new self($intervalSeconds, $timezone);
    }

    public function nextAfter(\DateTimeImmutable $instant): \DateTimeImmutable
    {
        $nextTimestamp = $instant->getTimestamp() + $this->intervalSeconds;

        return new \DateTimeImmutable('@'.$nextTimestamp)->setTimezone(new \DateTimeZone('UTC'));
    }

    public function localize(?\DateTimeImmutable $instant): ?\DateTimeImmutable
    {
        return $instant?->setTimezone(new \DateTimeZone($this->timezone));
    }
}
