<?php

declare(strict_types=1);

namespace App\Module\Video\Controller;

use App\Entity\ContentCategoryType;
use App\Entity\ContentTag;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Module\Video\Service\VideoStreamSourceResolver;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use App\Repository\ContentCategoryRepository;
use App\Repository\VideoRepository;
use App\Service\ContentTagManager;
use App\Service\ExternalVideoEmbedResolver;
use App\Service\VideoCsvImportParser;
use App\Service\VideoSourceUrlLock;
use App\Service\VideoThumbnailImportBudget;
use App\Service\VideoThumbnailImportService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/videos/import')]
#[IsGranted('ROLE_ADMIN')]
final class VideoCsvImportController extends AbstractController
{
    private const int MAX_FILE_BYTES = 2097152;
    private const int MAX_THUMBNAILS_PER_IMPORT = 50;
    private const int MAX_THUMBNAIL_BYTES_PER_IMPORT = 33554432;

    /** @var list<string> */
    private const array HEADERS = ['title', 'description', 'source_type', 'source_url', 'category', 'tags'];

    public function __construct(private readonly VideoThumbnailImportService $thumbnailImports)
    {
    }

    #[Route('', name: 'admin_video_import', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        VideoRepository $videos,
        ContentCategoryRepository $categories,
        ContentTagManager $tagManager,
        VideoCsvImportParser $csvParser,
        ExternalVideoEmbedResolver $embedResolver,
        VideoStreamSourceResolver $streamResolver,
        EntityManagerInterface $entityManager,
        VideoSourceUrlLock $sourceLock,
    ): Response {
        $errors = [];

        if ($request->isMethod('POST')) {
            $token = self::input($request->request, '_token');
            if ('' === $token || !$this->isCsrfTokenValid('admin_video_import_csv', $token)) {
                throw $this->createAccessDeniedException();
            }

            $upload = $request->files->get('csv_file');
            if (!$upload instanceof UploadedFile || !$upload->isValid()) {
                $errors[] = 'Bitte wähle eine gültige CSV-Datei aus.';
            } elseif ('csv' !== strtolower($upload->getClientOriginalExtension())) {
                $errors[] = 'Die Datei muss die Endung .csv haben.';
            } else {
                $size = $upload->getSize();
                if (false === $size || $size > self::MAX_FILE_BYTES) {
                    $errors[] = 'Die CSV-Datei darf höchstens 2 MiB groß sein.';
                } else {
                    $contents = file_get_contents($upload->getPathname());
                    if (false === $contents) {
                        $errors[] = 'Die CSV-Datei konnte nicht gelesen werden.';
                        $rows = [];
                    } else {
                        $parsedCsv = $csvParser->parse($contents);
                        $errors = $parsedCsv['errors'];
                        $rows = $parsedCsv['rows'];
                    }
                    if ([] === $errors && [] === $rows) {
                        $errors[] = 'Die CSV-Datei enthält keine Videozeilen.';
                    }

                    if ([] === $errors) {
                        $seenUrls = [];
                        foreach ($rows as &$row) {
                            $record = $row['record'];
                            if ('' === $row['title']) {
                                $row['title'] = self::fallbackTitle($row['source_url']);
                            }
                            if (mb_strlen($row['title']) > 180 || self::containsControlCharacters($row['title'])) {
                                $errors[] = sprintf('Datensatz %d: Der Titel fehlt, ist zu lang oder enthält unzulässige Steuerzeichen.', $record);
                            }
                            if (mb_strlen($row['description']) > 100000 || self::containsControlCharacters($row['description'])) {
                                $errors[] = sprintf('Datensatz %d: Die Beschreibung ist zu lang oder enthält unzulässige Steuerzeichen.', $record);
                            }
                            $sourceType = '' === $row['source_type']
                                ? self::inferSourceType($row['source_url'], $embedResolver, $streamResolver)
                                : VideoSourceType::tryFrom($row['source_type']);
                            if (null === $sourceType || VideoSourceType::File === $sourceType) {
                                $errors[] = sprintf('Datensatz %d: Die CSV-Quellart muss url, stream oder embed sein.', $record);
                            } elseif (VideoSourceType::Stream === $sourceType && null === $streamResolver->resolve($row['source_url'])) {
                                $errors[] = sprintf('Datensatz %d: Der Stream benötigt eine HTTPS-Mediendatei oder HLS-Wiedergabeliste auf einem freigegebenen Host.', $record);
                            } elseif (!Video::isSafeHttpsSource($row['source_url'])) {
                                $errors[] = sprintf('Datensatz %d: Die Videoquelle ist keine gültige HTTPS-Adresse.', $record);
                            } elseif (VideoSourceType::Embed === $sourceType && null === $embedResolver->resolve($row['source_url'])) {
                                $errors[] = sprintf('Datensatz %d: Für die Einbettung ist nur eine gültige Video-URL eines unterstützten Providers zulässig.', $record);
                            }
                            if (null !== $sourceType) {
                                $row['source_type'] = $sourceType->value;
                            }
                            if (mb_strlen($row['category']) > 100
                                || self::containsControlCharacters($row['category'])
                                || ('' !== $row['category'] && null === $categories->findForTypeAndName(ContentCategoryType::Video, $row['category']))
                            ) {
                                $errors[] = sprintf('Datensatz %d: Die Video-Kategorie ist unbekannt oder zu lang.', $record);
                            }
                            if (self::containsControlCharacters($row['tags'])) {
                                $errors[] = sprintf('Datensatz %d: Die Schlagwortliste enthält unzulässige Steuerzeichen.', $record);
                            }
                            if (mb_strlen($row['thumbnail_url']) > 2048
                                || self::containsControlCharacters($row['thumbnail_url'])
                                || ('' !== $row['thumbnail_url'] && !Video::isSafeHttpsSource($row['thumbnail_url']))
                            ) {
                                $errors[] = sprintf('Datensatz %d: Das Vorschaubild benötigt eine gültige HTTPS-Adresse ohne Zugangsdaten.', $record);
                            }
                            if ('' !== $row['duration_seconds']
                                && (!ctype_digit($row['duration_seconds'])
                                    || strlen($row['duration_seconds']) > 10
                                    || (int) $row['duration_seconds'] > 2147483647)
                            ) {
                                $errors[] = sprintf('Datensatz %d: Die Laufzeit muss eine ganze Zahl von 0 bis 2.147.483.647 Sekunden sein.', $record);
                            }
                            if (isset($seenUrls[$row['source_url']]) || null !== $videos->findBySourceUrl($row['source_url'])) {
                                $errors[] = sprintf('Datensatz %d: Diese Quell-URL ist bereits vorhanden.', $record);
                            }
                            $seenUrls[$row['source_url']] = true;

                            try {
                                ContentTagManager::parseNames($row['tags']);
                            } catch (\InvalidArgumentException $exception) {
                                $errors[] = sprintf('Datensatz %d: %s', $record, $exception->getMessage());
                            }
                            if (count($errors) >= 20) {
                                $errors[] = 'Weitere Fehler werden nicht angezeigt; korrigiere die Datei und importiere sie erneut.';
                                break;
                            }
                        }
                        unset($row);

                        $thumbnailCount = count(array_filter($rows, static fn (array $row): bool => '' !== $row['thumbnail_url']));
                        if ($thumbnailCount > self::MAX_THUMBNAILS_PER_IMPORT) {
                            $errors[] = sprintf('Pro Import dürfen höchstens %d externe Vorschaubilder übernommen werden.', self::MAX_THUMBNAILS_PER_IMPORT);
                        }

                        if ([] === $errors) {
                            /** @var array<int, array{object_id: string, mime_type: string, size: int}> $thumbnailsByRecord */
                            $thumbnailsByRecord = [];
                            $thumbnailObjectIds = [];
                            $thumbnailBytes = 0;
                            $thumbnailBudget = new VideoThumbnailImportBudget(
                                self::MAX_THUMBNAIL_BYTES_PER_IMPORT,
                                VideoThumbnailImportBudget::MAX_SECONDS,
                            );
                            foreach ($rows as $row) {
                                if ('' === $row['thumbnail_url']) {
                                    continue;
                                }
                                try {
                                    $thumbnail = $this->thumbnailImports->storeFromUrl($row['thumbnail_url'], $thumbnailBudget);
                                    $thumbnailObjectIds[] = $thumbnail['object_id'];
                                    $thumbnailBytes += $thumbnail['size'];
                                    if ($thumbnailBytes > self::MAX_THUMBNAIL_BYTES_PER_IMPORT) {
                                        $errors[] = 'Die Vorschaubilder dieses Imports überschreiten zusammen 32 MiB.';
                                        break;
                                    }
                                    $thumbnailsByRecord[$row['record']] = $thumbnail;
                                } catch (\InvalidArgumentException|PrivateStorageOperationFailed) {
                                    $errors[] = sprintf('Datensatz %d: Das Vorschaubild konnte nicht sicher übernommen werden.', $row['record']);
                                    break;
                                }
                            }
                        }

                        if ([] === $errors) {
                            /* @var array{created: int<0, max>, duplicates: list<int>} $importResult */
                            try {
                                $importResult = $entityManager->getConnection()->transactional(function (Connection $connection) use (
                                    $rows,
                                    $thumbnailsByRecord,
                                    $sourceLock,
                                    $videos,
                                    $tagManager,
                                    $entityManager,
                                ): array {
                                    $sourceLock->lock($connection, array_column($rows, 'source_url'));

                                    $duplicateRecords = [];
                                    foreach ($rows as $row) {
                                        if (null !== $videos->findBySourceUrl($row['source_url'])) {
                                            $duplicateRecords[] = $row['record'];
                                        }
                                    }
                                    if ([] !== $duplicateRecords) {
                                        return ['created' => 0, 'duplicates' => $duplicateRecords];
                                    }

                                    $tagNamesByNormalizedName = [];
                                    foreach ($rows as $row) {
                                        foreach (ContentTagManager::parseNames($row['tags']) as $tagName) {
                                            $tagNamesByNormalizedName[mb_strtolower($tagName)] ??= $tagName;
                                        }
                                    }
                                    $uniqueTagNames = array_values($tagNamesByNormalizedName);
                                    $resolvedTags = $tagManager->resolve($uniqueTagNames);
                                    $tagsByNormalizedName = [];
                                    foreach ($uniqueTagNames as $index => $tagName) {
                                        $resolvedTag = $resolvedTags[$index] ?? null;
                                        if ($resolvedTag instanceof ContentTag) {
                                            $tagsByNormalizedName[mb_strtolower($tagName)] = $resolvedTag;
                                        }
                                    }

                                    foreach ($rows as $row) {
                                        $tagAssignments = [];
                                        foreach (ContentTagManager::parseNames($row['tags']) as $tagName) {
                                            $tag = $tagsByNormalizedName[mb_strtolower($tagName)] ?? null;
                                            if ($tag instanceof ContentTag) {
                                                $tagAssignments[] = $tag;
                                            }
                                        }

                                        $video = new Video(
                                            $row['title'],
                                            $row['description'],
                                            '' === $row['category'] ? null : $row['category'],
                                            VideoSourceType::from($row['source_type']),
                                            $row['source_url'],
                                            PublishingStatus::Draft,
                                            false,
                                            false,
                                        );
                                        if ('' !== $row['duration_seconds']) {
                                            $video->setDurationSeconds((int) $row['duration_seconds']);
                                        }
                                        if (isset($thumbnailsByRecord[$row['record']])) {
                                            $thumbnail = $thumbnailsByRecord[$row['record']];
                                            $video->attachThumbnail($thumbnail['object_id'], $thumbnail['mime_type'], $thumbnail['size']);
                                        }
                                        $video->replaceTags($tagAssignments);
                                        $entityManager->persist($video);
                                    }

                                    $entityManager->flush();

                                    return ['created' => count($rows), 'duplicates' => []];
                                });
                            } catch (\Throwable $exception) {
                                if (!self::cleanupThumbnails($this->thumbnailImports, $thumbnailObjectIds)) {
                                    $this->addFlash('warning', 'Mindestens ein temporär importiertes Vorschaubild konnte nicht aus dem privaten Speicher entfernt werden.');
                                }

                                throw $exception;
                            }
                            if ([] !== $importResult['duplicates']) {
                                foreach ($importResult['duplicates'] as $record) {
                                    $errors[] = sprintf('Datensatz %d: Diese Quell-URL ist bereits vorhanden.', $record);
                                }
                            } else {
                                $this->addFlash('success', sprintf('%d Videos wurden als Entwürfe importiert.', $importResult['created']));

                                return $this->redirectToRoute('admin_video_index');
                            }
                        }
                        if (isset($thumbnailObjectIds)) {
                            if (!self::cleanupThumbnails($this->thumbnailImports, $thumbnailObjectIds)) {
                                $this->addFlash('warning', 'Mindestens ein temporär importiertes Vorschaubild konnte nicht aus dem privaten Speicher entfernt werden.');
                            }
                        }
                    }
                }
            }
        }

        return $this->render('admin/videos/import.html.twig', [
            'errors' => $errors,
            'headers' => self::HEADERS,
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    private static function containsControlCharacters(string $value): bool
    {
        return 1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value);
    }

    private static function inferSourceType(
        string $sourceUrl,
        ExternalVideoEmbedResolver $embedResolver,
        VideoStreamSourceResolver $streamResolver,
    ): VideoSourceType {
        if (null !== $embedResolver->resolve($sourceUrl)) {
            return VideoSourceType::Embed;
        }
        if (null !== $streamResolver->resolve($sourceUrl)) {
            return VideoSourceType::Stream;
        }

        return VideoSourceType::Url;
    }

    private static function fallbackTitle(string $sourceUrl): string
    {
        $host = parse_url($sourceUrl, PHP_URL_HOST);

        return 'Video von '.(is_string($host) && '' !== $host ? mb_substr($host, 0, 120) : 'unbekannter Quelle');
    }

    /** @param list<string> $objectIds */
    private static function cleanupThumbnails(VideoThumbnailImportService $thumbnailImports, array $objectIds): bool
    {
        $successful = true;
        foreach (array_unique($objectIds) as $objectId) {
            try {
                $thumbnailImports->delete($objectId);
            } catch (PrivateStorageOperationFailed|\InvalidArgumentException) {
                $successful = false;
            }
        }

        return $successful;
    }

    /**
     * @template TInput of string|int|float|bool|null
     *
     * @param InputBag<TInput> $bag
     */
    private static function input(InputBag $bag, string $key): string
    {
        $value = $bag->get($key);

        return is_string($value) ? $value : '';
    }
}
