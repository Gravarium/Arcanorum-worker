<?php

declare(strict_types=1);

namespace App\Module\Advertising\Http;

use App\Module\Advertising\Application\AdvertisingAccessDenied;
use App\Module\Advertising\Application\AdvertisingManager;
use App\Module\Advertising\Domain\AdFormat;
use App\Module\Advertising\Domain\AdStatistics;
use App\Module\Advertising\Domain\Advertisement;
use App\Module\Advertising\Domain\Placement;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/advertising')]
#[IsGranted('ROLE_ADMIN')]
final class AdvertisingAdminController extends AbstractController
{
    public function __construct(private readonly AdvertisingManager $manager)
    {
    }

    #[Route('/placements', name: 'admin_advertising_api_placements_list', methods: ['GET'])]
    public function listPlacements(): JsonResponse
    {
        try {
            return self::privateJson(new JsonResponse(array_map(self::placementData(...), $this->manager->listPlacements())));
        } catch (AdvertisingAccessDenied) {
            return self::error('forbidden', 403);
        }
    }

    #[Route('/placements', name: 'admin_advertising_api_placements_create', methods: ['POST'])]
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

    #[Route('/placements/{id}', name: 'admin_advertising_api_placements_update', methods: ['PATCH'])]
    public function updatePlacement(string $id, Request $request): JsonResponse
    {
        return $this->mutate($request, function (array $data, string $csrfToken) use ($id): array {
            self::assertRequestKeys($data, ['label', 'formats']);

            return self::placementData($this->manager->updatePlacement($id, self::requiredString($data, 'label'), self::stringList($data, 'formats'), $csrfToken));
        });
    }

    #[Route('/placements/{id}', name: 'admin_advertising_api_placements_archive', methods: ['DELETE'])]
    public function archivePlacement(string $id, Request $request): JsonResponse
    {
        return $this->mutate($request, fn (array $data, string $csrfToken): array => self::placementData($this->manager->archivePlacement($id, $csrfToken)), allowEmptyBody: true);
    }

    #[Route('/placements/{id}/activate', name: 'admin_advertising_api_placements_activate', methods: ['POST'])]
    public function activatePlacement(string $id, Request $request): JsonResponse
    {
        return $this->mutate($request, fn (array $data, string $csrfToken): array => self::placementData($this->manager->activatePlacement($id, $csrfToken)), allowEmptyBody: true);
    }

    #[Route('/placements/{id}/pause', name: 'admin_advertising_api_placements_pause', methods: ['POST'])]
    public function pausePlacement(string $id, Request $request): JsonResponse
    {
        return $this->mutate($request, fn (array $data, string $csrfToken): array => self::placementData($this->manager->pausePlacement($id, $csrfToken)), allowEmptyBody: true);
    }

    #[Route('/ads', name: 'admin_advertising_api_ads_list', methods: ['GET'])]
    public function listAdvertisements(Request $request): JsonResponse
    {
        try {
            $query = $request->query->all();
            $placementId = $query['placement'] ?? null;
            if (null !== $placementId && !is_string($placementId)) {
                return self::error('invalid_request', 400);
            }

            return self::privateJson(new JsonResponse(array_map(self::advertisementData(...), $this->manager->listAdvertisements($placementId))));
        } catch (AdvertisingAccessDenied) {
            return self::error('forbidden', 403);
        }
    }

    #[Route('/ads', name: 'admin_advertising_api_ads_create', methods: ['POST'])]
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

    #[Route('/ads/{id}', name: 'admin_advertising_api_ads_update', methods: ['PATCH'])]
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

    #[Route('/ads/{id}/activate', name: 'admin_advertising_api_ads_activate', methods: ['POST'])]
    public function activateAdvertisement(string $id, Request $request): JsonResponse
    {
        return $this->mutate($request, fn (array $data, string $csrfToken): array => self::advertisementData($this->manager->activateAdvertisement($id, $csrfToken)), allowEmptyBody: true);
    }

    #[Route('/ads/{id}/pause', name: 'admin_advertising_api_ads_pause', methods: ['POST'])]
    public function pauseAdvertisement(string $id, Request $request): JsonResponse
    {
        return $this->mutate($request, fn (array $data, string $csrfToken): array => self::advertisementData($this->manager->pauseAdvertisement($id, $csrfToken)), allowEmptyBody: true);
    }

    #[Route('/ads/{id}/deactivate', name: 'admin_advertising_api_ads_deactivate', methods: ['POST'])]
    public function deactivateAdvertisement(string $id, Request $request): JsonResponse
    {
        return $this->mutate($request, fn (array $data, string $csrfToken): array => self::advertisementData($this->manager->deactivateAdvertisement($id, $csrfToken)), allowEmptyBody: true);
    }

    #[Route('/ads/{id}', name: 'admin_advertising_api_ads_archive', methods: ['DELETE'])]
    public function archiveAdvertisement(string $id, Request $request): JsonResponse
    {
        return $this->mutate($request, fn (array $data, string $csrfToken): array => self::advertisementData($this->manager->archiveAdvertisement($id, $csrfToken)), allowEmptyBody: true);
    }

    #[Route('/statistics', name: 'admin_advertising_api_statistics', methods: ['GET'])]
    public function statistics(Request $request): JsonResponse
    {
        try {
            $from = $request->query->get('from');
            $through = $request->query->get('through');
            if (!is_string($from) || !is_string($through)) {
                return self::error('invalid_request', 400);
            }

            $rows = $this->manager->statistics(self::strictDate($from), self::strictDate($through));

            return self::privateJson(new JsonResponse(array_map(self::statisticsData(...), $rows)));
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

            return self::privateJson(new JsonResponse($operation($data, $csrfToken), $successStatus));
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
            'paused' => $placement->paused,
            'status' => $placement->archived ? 'archived' : ($placement->paused ? 'paused' : 'active'),
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
        return self::privateJson(new JsonResponse(['error' => $code], $status));
    }

    private static function privateJson(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    private static function strictDate(string $value): \DateTimeImmutable
    {
        if (1 !== preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $value)) {
            throw new \InvalidArgumentException('Date must use the YYYY-MM-DD format.');
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (false === $date || (false !== $errors && (0 < $errors['warning_count'] || 0 < $errors['error_count'])) || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Date is invalid.');
        }

        return $date;
    }
}
