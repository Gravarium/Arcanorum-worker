<?php

declare(strict_types=1);

namespace App\Module\Advertising\Http;

use App\Module\Advertising\Application\AdvertisingAccessDenied;
use App\Module\Advertising\Application\AdvertisingManager;
use App\Module\Advertising\Domain\AdFormat;
use App\Module\Advertising\Domain\AdStatistics;
use App\Module\Advertising\Domain\Advertisement;
use App\Module\Advertising\Domain\Placement;
use App\Module\Advertising\Infrastructure\AdvertisingAssetUploadService;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/advertising/manage', name: 'admin_advertising_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminAdvertisingController extends AbstractController
{
    /** @var array<string, string> */
    private const array STANDARD_SLOTS = [
        'home-top' => 'Startseite, oberhalb der Inhalte',
        'news-top' => 'Newsseite, unterhalb der Überschrift',
        'video-player-top' => 'Videoseite, oberhalb des Players',
        'video-player-bottom' => 'Videoseite, unterhalb des Players',
        Placement::VIDEO_PREROLL_ID => 'Videoseite, nicht überspringbare Vorab-Anzeige',
    ];

    /** @var array<string, list<string>> */
    private const array STANDARD_SLOT_FORMATS = [
        'home-top' => [AdFormat::HtmlBanner->value],
        'news-top' => [AdFormat::HtmlBanner->value],
        'video-player-top' => [AdFormat::StaticPlayer->value, AdFormat::Video->value],
        'video-player-bottom' => [AdFormat::StaticPlayer->value, AdFormat::Video->value],
        Placement::VIDEO_PREROLL_ID => [AdFormat::Video->value],
    ];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, AdvertisingManager $manager): Response
    {
        $formatValue = $request->query->getString('format');
        $format = AdFormat::tryFrom($formatValue);
        $playerFilter = 'player' === $formatValue;
        if ('' !== $formatValue && null === $format && !$playerFilter) {
            return $this->noStore(new Response('', Response::HTTP_BAD_REQUEST));
        }
        $advertisements = $manager->listAdvertisements();
        if ($playerFilter) {
            $advertisements = array_values(array_filter(
                $advertisements,
                static fn (Advertisement $advertisement): bool => in_array($advertisement->format, [AdFormat::StaticPlayer, AdFormat::Video], true),
            ));
        } elseif (null !== $format) {
            $advertisements = array_values(array_filter(
                $advertisements,
                static fn (Advertisement $advertisement): bool => $advertisement->format === $format,
            ));
        }

        $placements = $manager->listPlacements();
        $usedSlots = array_fill_keys(array_map(
            static fn (Placement $placement): string => $placement->id,
            $placements,
        ), true);
        $availableSlots = array_filter(
            self::STANDARD_SLOTS,
            static fn (string $id): bool => !isset($usedSlots[$id]),
            ARRAY_FILTER_USE_KEY,
        );

        return $this->noStore($this->render('admin/advertising/index.html.twig', [
            'placements' => $placements,
            'advertisements' => $advertisements,
            'format_filter' => $playerFilter ? 'player' : ($format->value ?? ''),
            'formats' => AdFormat::cases(),
            'standard_slots' => self::STANDARD_SLOTS,
            'available_slots' => $availableSlots,
        ]));
    }

    #[Route('/placements/new', name: 'placement_new', methods: ['GET', 'POST'])]
    public function newPlacement(Request $request, AdvertisingManager $manager): Response
    {
        $availableSlots = $this->availableSlots($manager);
        $defaultSlot = $availableSlots[0] ?? null;
        $values = [
            'id' => is_string($defaultSlot) ? $defaultSlot : '',
            'label' => '',
            'formats' => is_string($defaultSlot) ? self::STANDARD_SLOT_FORMATS[$defaultSlot] : [AdFormat::HtmlBanner->value],
        ];
        $errors = [];

        if ($request->isMethod('POST')) {
            try {
                $payload = $request->request->all();
                $values = [
                    'id' => self::formString($payload, 'id'),
                    'label' => self::formString($payload, 'label'),
                    'formats' => self::formStringList($payload, 'formats'),
                ];
                $csrfToken = self::formString($payload, '_token');
                if (!$this->isCsrfTokenValid('admin_advertising_mutation', $csrfToken)) {
                    return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
                }

                if (!in_array($values['id'], $availableSlots, true)) {
                    throw new \InvalidArgumentException('Wähle eine noch freie Standardposition aus.');
                }
                self::assertPlacementFormatsAllowed($values['id'], $values['formats']);

                $manager->createPlacement($values['id'], $values['label'], $values['formats'], $csrfToken);
                $this->addFlash('success', 'Der Werbeplatz wurde angelegt.');

                return $this->noStore($this->redirectToRoute('admin_advertising_index'));
            } catch (\InvalidArgumentException|\DomainException $exception) {
                $errors[] = $exception->getMessage();
            } catch (AdvertisingAccessDenied) {
                return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
            } catch (\Throwable $exception) {
                $this->logFailure($exception);
                $errors[] = 'Der Werbeplatz konnte nicht gespeichert werden.';
            }
        }

        return $this->noStore($this->render('admin/advertising/placement_form.html.twig', [
            'is_edit' => false,
            'placement' => null,
            'values' => $values,
            'errors' => $errors,
            'available_slots' => $availableSlots,
            'standard_slots' => self::STANDARD_SLOTS,
            'standard_slot_formats' => self::STANDARD_SLOT_FORMATS,
            'allowed_formats' => self::STANDARD_SLOT_FORMATS[$values['id']] ?? [],
            'formats' => AdFormat::cases(),
        ]));
    }

    #[Route('/placements/{id}/edit', name: 'placement_edit', requirements: ['id' => '[a-z0-9-]{1,64}'], methods: ['GET', 'POST'])]
    public function editPlacement(string $id, Request $request, AdvertisingManager $manager): Response
    {
        $placement = $this->requiredPlacement($manager, $id);
        $values = ['id' => $placement->id, 'label' => $placement->label, 'formats' => array_map(static fn (AdFormat $format): string => $format->value, $placement->formats)];
        $errors = [];

        if ($request->isMethod('POST')) {
            try {
                $payload = $request->request->all();
                $values = [
                    'id' => $placement->id,
                    'label' => self::formString($payload, 'label'),
                    'formats' => self::formStringList($payload, 'formats'),
                ];
                $csrfToken = self::formString($payload, '_token');
                if (!$this->isCsrfTokenValid('admin_advertising_mutation', $csrfToken)) {
                    return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
                }

                self::assertPlacementFormatsAllowed($id, $values['formats'], array_map(
                    static fn (AdFormat $format): string => $format->value,
                    $placement->formats,
                ));

                $manager->updatePlacement($id, $values['label'], $values['formats'], $csrfToken);
                $this->addFlash('success', 'Der Werbeplatz wurde aktualisiert.');

                return $this->noStore($this->redirectToRoute('admin_advertising_index'));
            } catch (\InvalidArgumentException|\DomainException $exception) {
                $errors[] = $exception->getMessage();
            } catch (AdvertisingAccessDenied) {
                return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
            } catch (\Throwable $exception) {
                $this->logFailure($exception);
                $errors[] = 'Der Werbeplatz konnte nicht gespeichert werden.';
            }
        }

        return $this->noStore($this->render('admin/advertising/placement_form.html.twig', [
            'is_edit' => true,
            'placement' => $placement,
            'values' => $values,
            'errors' => $errors,
            'available_slots' => [],
            'standard_slots' => self::STANDARD_SLOTS,
            'standard_slot_formats' => self::STANDARD_SLOT_FORMATS,
            'allowed_formats' => self::allowedFormatsForPlacement($placement),
            'formats' => AdFormat::cases(),
        ]));
    }

    #[Route('/placements/{id}/pause', name: 'placement_pause', requirements: ['id' => '[a-z0-9-]{1,64}'], methods: ['POST'])]
    public function pausePlacement(string $id, Request $request, AdvertisingManager $manager): Response
    {
        return $this->mutate($request, fn (string $token) => $manager->pausePlacement($id, $token), 'Der Werbeplatz wurde pausiert.');
    }

    #[Route('/placements/{id}/activate', name: 'placement_activate', requirements: ['id' => '[a-z0-9-]{1,64}'], methods: ['POST'])]
    public function activatePlacement(string $id, Request $request, AdvertisingManager $manager): Response
    {
        return $this->mutate($request, fn (string $token) => $manager->activatePlacement($id, $token), 'Der Werbeplatz wurde aktiviert.');
    }

    #[Route('/placements/{id}/archive', name: 'placement_archive', requirements: ['id' => '[a-z0-9-]{1,64}'], methods: ['POST'])]
    public function archivePlacement(string $id, Request $request, AdvertisingManager $manager): Response
    {
        return $this->mutate($request, function (string $token) use ($id, $request, $manager): void {
            if ('1' !== $request->request->getString('confirm_archive')) {
                throw new \InvalidArgumentException('Bestätige zuerst das endgültige Archivieren des Werbeplatzes.');
            }
            $manager->archivePlacement($id, $token);
        }, 'Der Werbeplatz wurde archiviert.');
    }

    #[Route('/ads/new', name: 'ad_new', methods: ['GET', 'POST'])]
    public function newAdvertisement(Request $request, AdvertisingManager $manager, AdvertisingAssetUploadService $assets): Response
    {
        $values = self::emptyAdvertisementValues();
        $errors = [];
        $uploadedPaths = [];
        $originalValues = $values;

        if ($request->isMethod('POST')) {
            try {
                $payload = $request->request->all();
                $values = self::advertisementValues($payload, null);
                $originalValues = $values;
                $csrfToken = self::formString($payload, '_token');
                if (!$this->isCsrfTokenValid('admin_advertising_mutation', $csrfToken)) {
                    return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
                }

                $this->applyUploads($request, $payload, $values, $assets, $uploadedPaths);

                $manager->createAdvertisement(
                    $values['label'],
                    $values['placement_id'],
                    $values['format'],
                    self::creativeFromValues($values),
                    $values['destination_url'],
                    $csrfToken,
                );
                $this->addFlash('success', 'Die Anzeige wurde als Entwurf angelegt.');

                return $this->noStore($this->redirectToRoute('admin_advertising_index'));
            } catch (\InvalidArgumentException|\DomainException $exception) {
                $this->cleanupAssetPaths($uploadedPaths, $assets);
                $values = $originalValues;
                $errors[] = $exception->getMessage();
            } catch (AdvertisingAccessDenied) {
                $this->cleanupAssetPaths($uploadedPaths, $assets);

                return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
            } catch (\Throwable $exception) {
                $this->cleanupAssetPaths($uploadedPaths, $assets);
                $values = $originalValues;
                $this->logFailure($exception);
                $errors[] = 'Die Anzeige konnte nicht gespeichert werden.';
            }
        }

        return $this->noStore($this->render('admin/advertising/ad_form.html.twig', [
            'is_edit' => false,
            'advertisement' => null,
            'values' => $values,
            'errors' => $errors,
            'placements' => array_values(array_filter($manager->listPlacements(), static fn (Placement $placement): bool => !$placement->archived)),
            'formats' => AdFormat::cases(),
        ]));
    }

    #[Route('/ads/{id}/edit', name: 'ad_edit', requirements: ['id' => '[a-f0-9]{32}'], methods: ['GET', 'POST'])]
    public function editAdvertisement(string $id, Request $request, AdvertisingManager $manager, AdvertisingAssetUploadService $assets): Response
    {
        $advertisement = $this->requiredAdvertisement($manager, $id);
        $values = self::advertisementValues([], $advertisement);
        $originalValues = $values;
        $errors = [];
        $uploadedPaths = [];
        $oldAssetPaths = self::assetPaths($advertisement);

        if ($request->isMethod('POST')) {
            try {
                $payload = $request->request->all();
                $values = self::advertisementValues($payload, $advertisement);
                $originalValues = $values;
                $csrfToken = self::formString($payload, '_token');
                if (!$this->isCsrfTokenValid('admin_advertising_mutation', $csrfToken)) {
                    return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
                }

                $this->applyUploads($request, $payload, $values, $assets, $uploadedPaths);

                $manager->updateAdvertisement(
                    $id,
                    $values['label'],
                    self::creativeFromValues($values),
                    $values['destination_url'],
                    $csrfToken,
                );
                $this->cleanupAssetPaths($oldAssetPaths, $assets);
                $this->addFlash('success', 'Die Anzeige wurde aktualisiert.');

                return $this->noStore($this->redirectToRoute('admin_advertising_index'));
            } catch (\InvalidArgumentException|\DomainException $exception) {
                $this->cleanupAssetPaths($uploadedPaths, $assets);
                $values = $originalValues;
                $errors[] = $exception->getMessage();
            } catch (AdvertisingAccessDenied) {
                $this->cleanupAssetPaths($uploadedPaths, $assets);

                return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
            } catch (\Throwable $exception) {
                $this->cleanupAssetPaths($uploadedPaths, $assets);
                $values = $originalValues;
                $this->logFailure($exception);
                $errors[] = 'Die Anzeige konnte nicht gespeichert werden.';
            }
        }

        return $this->noStore($this->render('admin/advertising/ad_form.html.twig', [
            'is_edit' => true,
            'advertisement' => $advertisement,
            'values' => $values,
            'errors' => $errors,
            'placements' => [],
            'formats' => AdFormat::cases(),
        ]));
    }

    #[Route('/ads/{id}/activate', name: 'ad_activate', requirements: ['id' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function activateAdvertisement(string $id, Request $request, AdvertisingManager $manager): Response
    {
        return $this->mutate($request, fn (string $token) => $manager->activateAdvertisement($id, $token), 'Die Anzeige wurde aktiviert.');
    }

    #[Route('/ads/{id}/pause', name: 'ad_pause', requirements: ['id' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function pauseAdvertisement(string $id, Request $request, AdvertisingManager $manager): Response
    {
        return $this->mutate($request, fn (string $token) => $manager->pauseAdvertisement($id, $token), 'Die Anzeige wurde pausiert.');
    }

    #[Route('/ads/{id}/deactivate', name: 'ad_deactivate', requirements: ['id' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function deactivateAdvertisement(string $id, Request $request, AdvertisingManager $manager): Response
    {
        return $this->mutate($request, fn (string $token) => $manager->deactivateAdvertisement($id, $token), 'Die Anzeige wurde deaktiviert.');
    }

    #[Route('/ads/{id}/archive', name: 'ad_archive', requirements: ['id' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function archiveAdvertisement(string $id, Request $request, AdvertisingManager $manager): Response
    {
        return $this->mutate($request, function (string $token) use ($id, $request, $manager): void {
            if ('1' !== $request->request->getString('confirm_archive')) {
                throw new \InvalidArgumentException('Bestätige zuerst das endgültige Archivieren der Anzeige.');
            }
            $manager->archiveAdvertisement($id, $token);
        }, 'Die Anzeige wurde archiviert.');
    }

    #[Route('/statistics', name: 'statistics', methods: ['GET'])]
    public function statistics(Request $request, AdvertisingManager $manager): Response
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $fromText = $request->query->getString('from', $today->modify('-29 days')->format('Y-m-d'));
        $throughText = $request->query->getString('through', $today->format('Y-m-d'));
        /** @var list<AdStatistics> $rows */
        $rows = [];
        $errors = [];

        try {
            $from = self::strictDate($fromText);
            $through = self::strictDate($throughText);
            $rows = $manager->statistics($from, $through);
        } catch (\InvalidArgumentException $exception) {
            $fromText = '';
            $throughText = '';
            $errors[] = $exception->getMessage();
        } catch (\Throwable $exception) {
            $this->logFailure($exception);
            $errors[] = 'Der Anzeigenbericht konnte nicht geladen werden.';
        }

        return $this->noStore($this->render('admin/advertising/statistics.html.twig', [
            'from' => $fromText,
            'through' => $throughText,
            'rows' => $rows,
            'impressions' => array_sum(array_map(static fn (AdStatistics $row): int => $row->impressions, $rows)),
            'clicks' => array_sum(array_map(static fn (AdStatistics $row): int => $row->clicks, $rows)),
            'errors' => $errors,
        ]));
    }

    private function mutate(Request $request, callable $operation, string $successMessage): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->noStore(new Response('', Response::HTTP_METHOD_NOT_ALLOWED));
        }

        $payload = $request->request->all();
        try {
            $csrfToken = self::formString($payload, '_token');
        } catch (\InvalidArgumentException) {
            return $this->noStore(new Response('', Response::HTTP_BAD_REQUEST));
        }
        if (!$this->isCsrfTokenValid('admin_advertising_mutation', $csrfToken)) {
            return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
        }

        try {
            $operation($csrfToken);
            $this->addFlash('success', $successMessage);
        } catch (AdvertisingAccessDenied) {
            return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
        } catch (\InvalidArgumentException|\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            $this->logFailure($exception);
            $this->addFlash('error', 'Die Änderung konnte nicht gespeichert werden.');
        }

        return $this->noStore($this->redirectToRoute('admin_advertising_index'));
    }

    /** @return list<string> */
    private function availableSlots(AdvertisingManager $manager): array
    {
        $used = array_fill_keys(array_map(
            static fn (Placement $placement): string => $placement->id,
            $manager->listPlacements(),
        ), true);

        return array_values(array_filter(
            array_keys(self::STANDARD_SLOTS),
            static fn (string $id): bool => !isset($used[$id]),
        ));
    }

    /**
     * @param list<string> $formats
     * @param list<string> $existingFormats
     */
    private static function assertPlacementFormatsAllowed(string $placementId, array $formats, array $existingFormats = []): void
    {
        $allowedFormats = self::STANDARD_SLOT_FORMATS[$placementId]
            ?? array_map(static fn (AdFormat $format): string => $format->value, AdFormat::cases());
        $allowedFormats = array_values(array_unique([...$allowedFormats, ...$existingFormats]));

        if ([] !== array_diff($formats, $allowedFormats)) {
            throw new \InvalidArgumentException('Das gewählte Anzeigenformat ist für diese Position nicht erlaubt.');
        }
    }

    /** @return list<string> */
    private static function allowedFormatsForPlacement(Placement $placement): array
    {
        $allowed = self::STANDARD_SLOT_FORMATS[$placement->id]
            ?? array_map(static fn (AdFormat $format): string => $format->value, AdFormat::cases());

        return array_values(array_unique([
            ...$allowed,
            ...array_map(static fn (AdFormat $format): string => $format->value, $placement->formats),
        ]));
    }

    private function requiredPlacement(AdvertisingManager $manager, string $id): Placement
    {
        foreach ($manager->listPlacements() as $placement) {
            if ($placement->id === $id) {
                return $placement;
            }
        }

        throw $this->createNotFoundException('Der Werbeplatz wurde nicht gefunden.');
    }

    private function requiredAdvertisement(AdvertisingManager $manager, string $id): Advertisement
    {
        foreach ($manager->listAdvertisements() as $advertisement) {
            if ($advertisement->id === $id) {
                return $advertisement;
            }
        }

        throw $this->createNotFoundException('Die Anzeige wurde nicht gefunden.');
    }

    /** @param array<string, mixed> $payload */
    private static function formString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? '';
        if (!is_string($value) || strlen($value) > 64000 || !mb_check_encoding($value, 'UTF-8')) {
            throw new \InvalidArgumentException('Die übermittelte Eingabe ist ungültig oder zu groß.');
        }

        return $value;
    }

    /** @param array<string, mixed> $payload
     * @return list<string>
     */
    private static function formStringList(array $payload, string $key): array
    {
        $values = $payload[$key] ?? [];
        if (!is_array($values) || !array_is_list($values)) {
            throw new \InvalidArgumentException('Die übermittelte Formatliste ist ungültig.');
        }

        $result = [];
        foreach ($values as $value) {
            if (!is_string($value) || strlen($value) > 64 || !mb_check_encoding($value, 'UTF-8')) {
                throw new \InvalidArgumentException('Die übermittelte Formatliste ist ungültig.');
            }
            $result[] = $value;
        }

        return $result;
    }

    /** @param array<string, mixed> $payload
     * @return array{label: string, placement_id: string, format: string, destination_url: string, html: string, image_path: string, alt: string, caption: string, video_path: string, poster_path: string}
     */
    private static function advertisementValues(array $payload, ?Advertisement $existing): array
    {
        $format = $existing?->format->value ?? self::formString($payload, 'format');
        $creative = $existing->creative ?? [];
        $caption = array_key_exists('caption', $payload) ? self::formString($payload, 'caption') : ($creative['caption'] ?? '');

        return [
            'label' => array_key_exists('label', $payload) ? self::formString($payload, 'label') : ($existing->label ?? ''),
            'placement_id' => $existing->placementId ?? self::formString($payload, 'placement_id'),
            'format' => $format,
            'destination_url' => array_key_exists('destination_url', $payload) ? self::formString($payload, 'destination_url') : ($existing->destinationUrl ?? ''),
            'html' => array_key_exists('html', $payload) ? self::formString($payload, 'html') : ($creative['html'] ?? ''),
            'image_path' => array_key_exists('image_path', $payload) ? self::formString($payload, 'image_path') : ($creative['image_path'] ?? ''),
            'alt' => array_key_exists('alt', $payload) ? self::formString($payload, 'alt') : ($creative['alt'] ?? ''),
            'caption' => $caption,
            'video_path' => array_key_exists('video_path', $payload) ? self::formString($payload, 'video_path') : ($creative['video_path'] ?? ''),
            'poster_path' => array_key_exists('poster_path', $payload) ? self::formString($payload, 'poster_path') : ($creative['poster_path'] ?? ''),
        ];
    }

    /** @return array{label: string, placement_id: string, format: string, destination_url: string, html: string, image_path: string, alt: string, caption: string, video_path: string, poster_path: string} */
    private static function emptyAdvertisementValues(): array
    {
        return [
            'label' => '',
            'placement_id' => '',
            'format' => AdFormat::HtmlBanner->value,
            'destination_url' => '',
            'html' => '',
            'image_path' => '',
            'alt' => '',
            'caption' => '',
            'video_path' => '',
            'poster_path' => '',
        ];
    }

    /** @param array{label: string, placement_id: string, format: string, destination_url: string, html: string, image_path: string, alt: string, caption: string, video_path: string, poster_path: string} $values
     * @return array<string, string>
     */
    private static function creativeFromValues(array $values): array
    {
        return match ($values['format']) {
            AdFormat::HtmlBanner->value => ['html' => $values['html']],
            AdFormat::StaticPlayer->value => [
                'image_path' => $values['image_path'],
                'alt' => $values['alt'],
                'caption' => $values['caption'],
            ],
            AdFormat::Video->value => [
                'provider' => 'local',
                'video_path' => $values['video_path'],
                'poster_path' => $values['poster_path'],
                'caption' => $values['caption'],
            ],
            default => [],
        };
    }

    /**
     * @param array<string, mixed>                                                                                                                                                                         $payload
     * @param array{label: string, placement_id: string, format: string, destination_url: string, html: string, image_path: string, alt: string, caption: string, video_path: string, poster_path: string} $values
     * @param list<string>                                                                                                                                                                                 $uploadedPaths
     */
    private function applyUploads(Request $request, array $payload, array &$values, AdvertisingAssetUploadService $assets, array &$uploadedPaths): void
    {
        $bannerImage = self::uploadedFile($request, 'banner_image_upload');
        $playerImage = self::uploadedFile($request, 'image_upload');
        $video = self::uploadedFile($request, 'video_upload');
        $poster = self::uploadedFile($request, 'poster_upload');

        if (AdFormat::HtmlBanner->value === $values['format']) {
            if (null !== $playerImage || null !== $video || null !== $poster) {
                throw new \InvalidArgumentException('Verwende nur den Bild-Upload für HTML-Banner.');
            }
            if (null !== $bannerImage) {
                $path = $assets->storeImage($bannerImage);
                $uploadedPaths[] = $path;
                $alt = self::formString($payload, 'banner_image_alt');
                if (mb_strlen($alt) > 160) {
                    throw new \InvalidArgumentException('Der Alternativtext darf höchstens 160 Zeichen enthalten.');
                }
                $values['html'] = rtrim($values['html']).sprintf(
                    '<img src="%s" alt="%s">',
                    htmlspecialchars($path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                );
            }

            return;
        }

        if (null !== $bannerImage) {
            throw new \InvalidArgumentException('Der Bild-Upload für HTML-Banner passt nicht zu diesem Anzeigenformat.');
        }
        if (AdFormat::StaticPlayer->value === $values['format']) {
            if (null !== $video || null !== $poster) {
                throw new \InvalidArgumentException('Verwende nur den Bild-Upload für statische Player-Anzeigen.');
            }
            if (null !== $playerImage) {
                $values['image_path'] = $assets->storeImage($playerImage);
                $uploadedPaths[] = $values['image_path'];
            }

            return;
        }

        if (AdFormat::Video->value === $values['format']) {
            if (null !== $playerImage) {
                throw new \InvalidArgumentException('Verwende für Videoanzeigen den Video- und Vorschaubild-Upload.');
            }
            if (null !== $video) {
                $values['video_path'] = $assets->storeVideo($video);
                $uploadedPaths[] = $values['video_path'];
            }
            if (null !== $poster) {
                $values['poster_path'] = $assets->storeImage($poster);
                $uploadedPaths[] = $values['poster_path'];
            }
        }
    }

    private static function uploadedFile(Request $request, string $key): ?UploadedFile
    {
        $file = $request->files->get($key);
        if (null === $file) {
            return null;
        }
        if (!$file instanceof UploadedFile) {
            throw new \InvalidArgumentException('Pro Feld kann nur eine einzelne Anzeigen-Datei hochgeladen werden.');
        }

        return $file;
    }

    /** @return list<string> */
    private static function assetPaths(Advertisement $advertisement): array
    {
        $paths = [];
        foreach ($advertisement->creative as $value) {
            $matchCount = preg_match_all('~/media/ads/[a-f0-9]{32}\.(?:jpg|png|webp|mp4)~', $value, $matches);
            if (is_int($matchCount) && $matchCount > 0) {
                array_push($paths, ...$matches[0]);
            }
        }

        return array_values(array_unique($paths));
    }

    /** @param list<string> $paths */
    private function cleanupAssetPaths(array $paths, AdvertisingAssetUploadService $assets): void
    {
        foreach (array_unique($paths) as $path) {
            try {
                $assets->deleteIfUnreferenced($path);
            } catch (\Throwable $exception) {
                $this->logger->warning('Nicht verwendete Anzeigen-Datei konnte nicht bereinigt werden.', [
                    'failure_type' => $exception::class,
                ]);
            }
        }
    }

    private static function strictDate(string $value): \DateTimeImmutable
    {
        if (1 !== preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $value)) {
            throw new \InvalidArgumentException('Wähle einen gültigen Zeitraum aus.');
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (false === $date || (false !== $errors && (0 < $errors['warning_count'] || 0 < $errors['error_count'])) || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Wähle einen gültigen Zeitraum aus.');
        }

        return $date;
    }

    private function logFailure(\Throwable $exception): void
    {
        $context = ['failure_type' => $exception::class];
        if ($exception instanceof DbalException) {
            $this->logger->error('Anzeigenverwaltung konnte die Datenbankänderung nicht abschließen.', $context);

            return;
        }

        $this->logger->error('Anzeigenverwaltung konnte die Änderung nicht abschließen.', $context);
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
