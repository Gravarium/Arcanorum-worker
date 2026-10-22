<?php

declare(strict_types=1);

namespace App\Module\Advertising\Application;

use App\Module\Advertising\Domain\AdFormat;
use App\Module\Advertising\Domain\AdStatistics;
use App\Module\Advertising\Domain\AdStatus;
use App\Module\Advertising\Domain\Advertisement;
use App\Module\Advertising\Domain\Placement;
use App\Module\Advertising\Domain\SafeAssetPath;
use App\Module\Advertising\Domain\SafeDestination;
use App\Module\Advertising\Domain\SafeHtml;

final readonly class AdvertisingManager
{
    public function __construct(
        private AdvertisingStore $store,
        private StatisticsStore $statistics,
        private AdvertisingAdminGuard $guard,
    ) {
    }

    /**
     * @param list<string> $formats
     */
    public function createPlacement(string $id, string $label, array $formats, string $csrfToken): Placement
    {
        $this->authorizeMutation($csrfToken);
        if (null !== $this->store->findPlacement($id)) {
            throw new \DomainException('Placement already exists.');
        }

        $placement = new Placement($id, $label, self::formats($formats));
        $this->store->savePlacement($placement);

        return $placement;
    }

    /**
     * @param list<string> $formats
     */
    public function updatePlacement(string $id, string $label, array $formats, string $csrfToken): Placement
    {
        $this->authorizeMutation($csrfToken);
        $placement = $this->requiredPlacement($id);
        if ($placement->archived) {
            throw new \DomainException('Archived placements cannot be edited.');
        }

        $updated = $placement->revise($label, self::formats($formats));
        foreach ($this->store->listAdvertisements($id) as $advertisement) {
            if (AdStatus::Archived !== $advertisement->status && !$updated->supports($advertisement->format)) {
                throw new \DomainException('Archive advertisements using this format before removing it.');
            }
        }

        $this->store->savePlacement($updated);

        return $updated;
    }

    public function archivePlacement(string $id, string $csrfToken): Placement
    {
        $this->authorizeMutation($csrfToken);
        $placement = $this->requiredPlacement($id);
        if ([] !== $this->store->listAdvertisementsByStatus($id, AdStatus::Active)) {
            throw new \DomainException('Pause or deactivate active advertisements before archiving a placement.');
        }

        $archived = $placement->archive();
        $this->store->savePlacement($archived);

        return $archived;
    }

    public function activatePlacement(string $id, string $csrfToken): Placement
    {
        $this->authorizeMutation($csrfToken);
        $placement = $this->requiredPlacement($id);
        $active = $placement->activate();
        $this->store->savePlacement($active);

        return $active;
    }

    public function pausePlacement(string $id, string $csrfToken): Placement
    {
        $this->authorizeMutation($csrfToken);
        $placement = $this->requiredPlacement($id);
        $paused = $placement->pause();
        $this->store->savePlacement($paused);

        return $paused;
    }

    /**
     * @param array<string, mixed> $creative
     */
    public function createAdvertisement(
        string $label,
        string $placementId,
        string $format,
        array $creative,
        string $destinationUrl,
        string $csrfToken,
    ): Advertisement {
        $this->authorizeMutation($csrfToken);
        $placement = $this->requiredPlacement($placementId);
        if ($placement->archived) {
            throw new \DomainException('Advertisements cannot be added to archived placements.');
        }

        $adFormat = AdFormat::tryFrom($format);
        if (null === $adFormat || !$placement->supports($adFormat)) {
            throw new \InvalidArgumentException('Advertising format is not supported by this placement.');
        }

        $now = new \DateTimeImmutable();
        $advertisement = new Advertisement(
            bin2hex(random_bytes(16)),
            $label,
            $placementId,
            $adFormat,
            self::normalizeCreative($adFormat, $creative),
            SafeDestination::fromString($destinationUrl)->url,
            AdStatus::Draft,
            $now,
            $now,
        );
        $this->store->saveAdvertisement($advertisement);

        return $advertisement;
    }

    /**
     * @param array<string, mixed> $creative
     */
    public function updateAdvertisement(
        string $id,
        string $label,
        array $creative,
        string $destinationUrl,
        string $csrfToken,
    ): Advertisement {
        $this->authorizeMutation($csrfToken);
        $advertisement = $this->requiredAdvertisement($id);
        $updated = $advertisement->revise(
            $label,
            self::normalizeCreative($advertisement->format, $creative),
            SafeDestination::fromString($destinationUrl)->url,
            new \DateTimeImmutable(),
        );
        $this->store->saveAdvertisement($updated);

        return $updated;
    }

    public function activateAdvertisement(string $id, string $csrfToken): Advertisement
    {
        $this->authorizeMutation($csrfToken);
        $advertisement = $this->requiredAdvertisement($id);
        $placement = $this->requiredPlacement($advertisement->placementId);
        if ($placement->archived || !$placement->supports($advertisement->format)) {
            throw new \DomainException('Advertisement placement is not available for this format.');
        }

        self::validateStoredCreative($advertisement);
        SafeDestination::fromString($advertisement->destinationUrl);
        $active = $advertisement->transitionTo(AdStatus::Active, new \DateTimeImmutable());
        $this->store->saveAdvertisement($active);

        return $active;
    }

    public function pauseAdvertisement(string $id, string $csrfToken): Advertisement
    {
        return $this->transitionAdvertisement($id, AdStatus::Paused, $csrfToken);
    }

    public function deactivateAdvertisement(string $id, string $csrfToken): Advertisement
    {
        return $this->transitionAdvertisement($id, AdStatus::Inactive, $csrfToken);
    }

    public function archiveAdvertisement(string $id, string $csrfToken): Advertisement
    {
        return $this->transitionAdvertisement($id, AdStatus::Archived, $csrfToken);
    }

    /** @return list<Placement> */
    public function listPlacements(): array
    {
        $this->guard->assertCanManageAdvertising();

        return $this->store->listPlacements();
    }

    /** @return list<Advertisement> */
    public function listAdvertisements(?string $placementId = null): array
    {
        $this->guard->assertCanManageAdvertising();

        return $this->store->listAdvertisements($placementId);
    }

    /** @return list<AdStatistics> */
    public function statistics(\DateTimeImmutable $from, \DateTimeImmutable $through): array
    {
        $this->guard->assertCanManageAdvertising();
        if ($through < $from || $through->diff($from)->days > 366) {
            throw new \InvalidArgumentException('Statistics range must be ordered and no longer than 367 days.');
        }

        return $this->statistics->aggregate($from, $through);
    }

    private function transitionAdvertisement(string $id, AdStatus $status, string $csrfToken): Advertisement
    {
        $this->authorizeMutation($csrfToken);
        $updated = $this->requiredAdvertisement($id)->transitionTo($status, new \DateTimeImmutable());
        $this->store->saveAdvertisement($updated);

        return $updated;
    }

    private function authorizeMutation(string $csrfToken): void
    {
        $this->guard->assertCanManageAdvertising();
        if ('' === trim($csrfToken)) {
            throw new AdvertisingAccessDenied('A valid advertising CSRF token is required.');
        }

        $this->guard->assertValidCsrfToken($csrfToken);
    }

    private function requiredPlacement(string $id): Placement
    {
        return $this->store->findPlacement($id) ?? throw new \DomainException('Placement was not found.');
    }

    private function requiredAdvertisement(string $id): Advertisement
    {
        return $this->store->findAdvertisement($id) ?? throw new \DomainException('Advertisement was not found.');
    }

    /**
     * @param list<string> $values
     *
     * @return list<AdFormat>
     */
    private static function formats(array $values): array
    {
        if ([] === $values) {
            throw new \InvalidArgumentException('A placement must support at least one advertising format.');
        }

        $formats = [];
        foreach ($values as $value) {
            $format = AdFormat::tryFrom($value);
            if (null === $format) {
                throw new \InvalidArgumentException('Unsupported advertising format.');
            }
            $formats[] = $format;
        }

        return $formats;
    }

    /**
     * @param array<string, mixed> $creative
     *
     * @return array<string, string>
     */
    private static function normalizeCreative(AdFormat $format, array $creative): array
    {
        return match ($format) {
            AdFormat::HtmlBanner => self::normalizeHtmlBanner($creative),
            AdFormat::StaticPlayer => self::normalizeStaticPlayer($creative),
            AdFormat::Video => self::normalizeVideo($creative),
        };
    }

    /**
     * @param array<string, mixed> $creative
     *
     * @return array<string, string>
     */
    private static function normalizeHtmlBanner(array $creative): array
    {
        self::assertKeys($creative, ['html']);
        $html = self::requiredText($creative, 'html', 50000, false);
        $safeHtml = SafeHtml::sanitize($html)->html;
        if ('' === trim($safeHtml)) {
            throw new \InvalidArgumentException('HTML banner must contain safe content.');
        }

        return ['html' => $safeHtml];
    }

    /**
     * @param array<string, mixed> $creative
     *
     * @return array<string, string>
     */
    private static function normalizeStaticPlayer(array $creative): array
    {
        self::assertKeys($creative, ['image_path', 'alt', 'caption']);
        $imagePath = self::requiredText($creative, 'image_path', 1024);
        $alt = self::requiredText($creative, 'alt', 160, false);
        $caption = self::requiredText($creative, 'caption', 240);

        return [
            'image_path' => SafeAssetPath::fromString($imagePath)->path,
            'alt' => $alt,
            'caption' => $caption,
        ];
    }

    /**
     * @param array<string, mixed> $creative
     *
     * @return array<string, string>
     */
    private static function normalizeVideo(array $creative): array
    {
        self::assertKeys($creative, ['provider', 'video_path', 'poster_path', 'caption']);
        if ('local' !== self::requiredText($creative, 'provider', 32)) {
            throw new \InvalidArgumentException('Video provider is not allowed. Only same-origin video assets are supported.');
        }

        $videoPath = SafeAssetPath::fromString(self::requiredText($creative, 'video_path', 1024))->path;
        $posterPath = SafeAssetPath::fromString(self::requiredText($creative, 'poster_path', 1024))->path;
        if (1 !== preg_match('/\.mp4\z/i', $videoPath) || 1 !== preg_match('/\.(?:png|jpe?g|webp)\z/i', $posterPath)) {
            throw new \InvalidArgumentException('Video and poster assets must use MP4 and a supported image format.');
        }

        return [
            'provider' => 'local',
            'video_path' => $videoPath,
            'poster_path' => $posterPath,
            'caption' => self::requiredText($creative, 'caption', 240),
        ];
    }

    /**
     * @param array<string, mixed> $creative
     * @param list<string>         $allowed
     */
    private static function assertKeys(array $creative, array $allowed): void
    {
        foreach ($creative as $key => $_value) {
            if (!in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException('Creative fields do not match the selected advertising format.');
            }
        }

        foreach ($allowed as $key) {
            if (!array_key_exists($key, $creative)) {
                throw new \InvalidArgumentException('Creative fields do not match the selected advertising format.');
            }
        }
    }

    /** @param array<string, mixed> $values */
    private static function requiredText(array $values, string $key, int $maximumLength, bool $trim = true): string
    {
        $value = $values[$key] ?? null;
        if (!is_string($value) || strlen($value) > $maximumLength || !mb_check_encoding($value, 'UTF-8')) {
            throw new \InvalidArgumentException(sprintf('Creative field %s is missing or too long.', $key));
        }

        $normalized = $trim ? trim($value) : $value;
        if ('' === trim($normalized) && 'alt' !== $key && 'html' !== $key) {
            throw new \InvalidArgumentException(sprintf('Creative field %s cannot be empty.', $key));
        }

        return $normalized;
    }

    private static function validateStoredCreative(Advertisement $advertisement): void
    {
        self::normalizeCreative($advertisement->format, $advertisement->creative);
    }
}
