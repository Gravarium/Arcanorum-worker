<?php

declare(strict_types=1);

namespace App\Module\Advertising\Http;

use App\Module\Advertising\Application\AdvertisingAccessDenied;
use App\Module\Advertising\Application\AdvertisingManager;
use App\Module\Advertising\Domain\AdFormat;
use App\Module\Advertising\Domain\AdStatistics;
use App\Module\Advertising\Domain\Advertisement;
use App\Module\Advertising\Domain\Placement;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/advertising')]
final readonly class AdvertisingAdminController
{
    public function __construct(private AdvertisingManager $manager)
    {
    }

    #[Route('/placements', name: 'cms_advertising_placements_list', methods: ['GET'])]
    public function listPlacements(): JsonResponse
    {
        try {
            return new JsonResponse(array_map(self::placementData(...), $this->manager->listPlacements()));
        } catch (AdvertisingAccessDenied) {
            return self::error('forbidden', 403);
        }
    }

    #[Route('/placements', name: 'cms_advertising_placements_create', methods: ['POST'])]
    public function createPlacement(Request $request): JsonResponse
    {
        return $this->mutate($request, function (array $data, string $csrfToken): array {
            self::assertRequestKeys($data, ['id', 'label', 'formats']);
            $placement = $this->manager->createPlacement(
                self::requiredString($data, 'id'),
                self::requiredString($data, 'label'),
                self::stringList($data, 'formats'),
                $csrfToken,
            );

            return self::placementData($placement);
        }, 201);
    }

    #[Route('/placements/{id}', name: 'cms_advertising_placements_update', methods: ['PATCH'])]
    public function updatePlacement(string $id, Request $request): JsonResponse
    {
        return $this->mutate($request, function (array $data, string $csrfToken) use ($id): array {
            self::assertRequestKeys($data, ['label', 'formats']);

            return self::placementData($this->manager->updatePlacement($id, self::requiredString($data, 'label'), self::stringList($data, 'formats'), $csrfToken));
        });
    }

    #[Route('/placements/{id}', name: 'cms_advertising_placements_archive', methods: ['DELETE'])]
    public function archivePlacement(string $id, Request $request): JsonResponse
    {
        return $this->mutate($request, fn (array $data, string $csrfToken): array => self::placementData($this->manager->archivePlacement($id, $csrfToken)), allowEmptyBody: true);
    }

    #[Route('/ads', name: 'cms_advertising_ads_list', methods: ['GET'])]
    public function listAdvertisements(Request $request): JsonResponse
    {
        try {
            $placementId = $request->query->get('placement');

            return new JsonResponse(array_map(self::advertisementData(...), $this->manager->listAdvertisements($placementId)));
        } catch (AdvertisingAccessDenied) {
            return self::error('forbidden', 403);
        }
    }

    #[Route('/ads', name: 'cms_advertising_ads_create', methods: ['POST'])]
    public function createAdvertisement(Request $request): JsonResponse
    {
        return $this->mutate($request, function (array $data, string $csrfToken): array {
            self::assertRequestKeys($data, ['label', 'placement_id', 'format', 'creative', 'destination_url']);
            $advertisement = $this->manager->createAdvertisement(
                self::requiredString($data, 'label'),
                self::requiredString($data, 'placement_id'),
                self::requiredString($data, 'format'),
                self::objectField($data, 'creative'),
                self::requiredString($data, 'destination_url'),
                $csrfToken,
            );

            return self::advertisementData($advertisement);
        }, 201);
    }

    #[Route('/ads/{id}', name: 'cms_advertising_ads_update', methods: ['PATCH'])]
    public function updateAdvertisement(string $id, Request $request): JsonResponse
    {
        return $this->mutate($request, function (array $data, string $csrfToken) use ($id): array {
            self::assertRequestKeys($data, ['label', 'creative', 'destination_url']);

            return self::advertisementData($this->manager->updateAdvertisement(
                $id,
                self::requiredString($data, 'label'),
                self::objectField($data, 'creative'),
                self::requiredString($data, 'destination_url'),
                $csrfToken,
            ));
        });
    }

    #[Route('/ads/{id}/activate', name: 'cms_advertising_ads_activate', methods: ['POST'])]
    public function activateAdvertisement(string $id, Request $request): JsonResponse
    {
        return $this->mutate($request, fn (array $data, string $csrfToken): array => self::advertisementData($this->manager->activateAdvertisement($id, $csrfToken)), allowEmptyBody: true);
    }

    #[Route('/ads/{id}/pause', name: 'cms_advertising_ads_pause', methods: ['POST'])]
    public function pauseAdvertisement(string $id, Request $request): JsonResponse
    {
        return $this->mutate($request, fn (array $data, string $csrfToken): array => self::advertisementData($this->manager->pauseAdvertisement($id, $csrfToken)), allowEmptyBody: true);
    }

    #[Route('/ads/{id}/deactivate', name: 'cms_advertising_ads_deactivate', methods: ['POST'])]
    public function deactivateAdvertisement(string $id, Request $request): JsonResponse
    {
        return $this->mutate($request, fn (array $data, string $csrfToken): array => self::advertisementData($this->manager->deactivateAdvertisement($id, $csrfToken)), allowEmptyBody: true);
    }

    #[Route('/ads/{id}', name: 'cms_advertising_ads_archive', methods: ['DELETE'])]
    public function archiveAdvertisement(string $id, Request $request): JsonResponse
    {
        return $this->mutate($request, fn (array $data, string $csrfToken): array => self::advertisementData($this->manager->archiveAdvertisement($id, $csrfToken)), allowEmptyBody: true);
    }

    #[Route('/statistics', name: 'cms_advertising_statistics', methods: ['GET'])]
    public function statistics(Request $request): JsonResponse
    {
        try {
            $from = $request->query->get('from');
            $through = $request->query->get('through');
            if (!is_string($from) || !is_string($through)) {
                return self::error('invalid_request', 400);
            }

            $rows = $this->manager->statistics(new \DateTimeImmutable($from), new \DateTimeImmutable($through));

            return new JsonResponse(array_map(self::statisticsData(...), $rows));
        } catch (AdvertisingAccessDenied) {
            return self::error('forbidden', 403);
        } catch (\Throwable) {
            return self::error('invalid_request', 400);
        }
    }

    /** @param callable(array<string, mixed>, string): array<string, mixed> $operation */
    private function mutate(Request $request, callable $operation, int $successStatus = 200, bool $allowEmptyBody = false): JsonResponse
    {
        try {
            $data = $allowEmptyBody ? [] : self::jsonObject($request);
            $csrfToken = $request->headers->get('X-CSRF-Token') ?? '';

            return new JsonResponse($operation($data, $csrfToken), $successStatus);
        } catch (\JsonException|\InvalidArgumentException) {
            return self::error('invalid_request', 400);
        } catch (AdvertisingAccessDenied) {
            return self::error('forbidden', 403);
        } catch (\DomainException) {
            return self::error('state_conflict', 409);
        }
    }

    /** @return array<string, mixed> */
    private static function jsonObject(Request $request): array
    {
        $content = $request->getContent();
        if (strlen($content) > 64000) {
            throw new \InvalidArgumentException('Request body exceeds the module limit.');
        }

        try {
            $data = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw $exception;
        }

        if (!is_array($data) || array_is_list($data)) {
            throw new \InvalidArgumentException('Request body must be a JSON object.');
        }

        $object = [];
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Request body must be a JSON object.');
            }
            $object[$key] = $value;
        }

        return $object;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $allowed
     */
    private static function assertRequestKeys(array $data, array $allowed): void
    {
        foreach ($data as $key => $_value) {
            if (!in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException('Request contains unsupported fields.');
            }
        }
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $data)) {
                throw new \InvalidArgumentException('Request is missing required fields.');
            }
        }
    }

    /** @param array<string, mixed> $data */
    private static function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Required field is invalid.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function objectField(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException('Required creative is invalid.');
        }

        $object = [];
        foreach ($value as $field => $fieldValue) {
            if (!is_string($field)) {
                throw new \InvalidArgumentException('Required creative is invalid.');
            }
            $object[$field] = $fieldValue;
        }

        return $object;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private static function stringList(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException('Required list is invalid.');
        }

        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new \InvalidArgumentException('Required list is invalid.');
            }
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private static function placementData(Placement $placement): array
    {
        return [
            'id' => $placement->id,
            'label' => $placement->label,
            'formats' => array_map(static fn (AdFormat $format): string => $format->value, $placement->formats),
            'archived' => $placement->archived,
        ];
    }

    /** @return array<string, mixed> */
    private static function advertisementData(Advertisement $advertisement): array
    {
        $creative = $advertisement->creative;
        if ('html_banner' === $advertisement->format->value) {
            $creative['html'] = \App\Module\Advertising\Domain\SafeHtml::sanitize($creative['html'] ?? '')->html;
        }

        return [
            'id' => $advertisement->id,
            'label' => $advertisement->label,
            'placement_id' => $advertisement->placementId,
            'format' => $advertisement->format->value,
            'creative' => $creative,
            'destination_url' => $advertisement->destinationUrl,
            'status' => $advertisement->status->value,
        ];
    }

    /** @return array<string, int|string> */
    private static function statisticsData(AdStatistics $statistics): array
    {
        return [
            'advertisement_id' => $statistics->advertisementId,
            'placement_id' => $statistics->placementId,
            'day' => $statistics->day,
            'impressions' => $statistics->impressions,
            'clicks' => $statistics->clicks,
        ];
    }

    private static function error(string $code, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $code], $status);
    }
}
