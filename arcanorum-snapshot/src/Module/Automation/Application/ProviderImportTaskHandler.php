<?php

declare(strict_types=1);

namespace App\Module\Automation\Application;

use App\Entity\ContentCategoryType;
use App\Entity\PublishingStatus;
use App\Entity\ScheduledTask;
use App\Entity\ScheduledTaskType;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Repository\ContentCategoryRepository;
use App\Repository\VideoRepository;
use App\Service\VideoProviderImportService;
use App\Service\VideoSourceUrlLock;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ProviderImportTaskHandler implements ScheduledTaskHandlerInterface
{
    public function __construct(
        private ScheduledTaskConfigurationValidator $configurationValidator,
        private VideoProviderImportService $providerImport,
        private VideoSourceUrlLock $sourceLock,
        private VideoRepository $videos,
        private ContentCategoryRepository $categories,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function type(): ScheduledTaskType
    {
        return ScheduledTaskType::ProviderImport;
    }

    public function execute(ScheduledTask $task, \DateTimeImmutable $now): string
    {
        $config = $this->configurationValidator->normalize(ScheduledTaskType::ProviderImport, $task->getConfiguration());
        $provider = $config['provider'];
        $mode = $config['mode'];
        $query = $config['query'];
        $channelId = $config['channel_id'];
        $category = $config['category'];
        $excludeTerms = $config['exclude_terms'];

        if (!is_string($provider) || !is_string($mode) || !is_string($query) || !is_string($channelId)
            || (null !== $category && !is_string($category)) || !is_array($excludeTerms)
        ) {
            throw new \LogicException('Die gespeicherte Importaufgabe ist ungültig.');
        }

        if (!$this->providerImport->isConfiguredForMode($provider, $mode)) {
            throw new \RuntimeException('Der Videoanbieter ist nicht konfiguriert.');
        }
        if (null !== $category && null === $this->categories->findForTypeAndName(ContentCategoryType::Video, $category)) {
            throw new \RuntimeException('Die gespeicherte Zielkategorie ist nicht mehr verfügbar.');
        }

        $items = $this->providerImport->search(
            $provider,
            'search' === $mode ? $query : '',
            'channel' === $mode ? $channelId : null,
        );
        $excludedCount = 0;
        $acceptedItems = [];
        foreach ($items as $item) {
            $haystack = mb_strtolower($item['title'].' '.$item['description'], 'UTF-8');
            $excluded = false;
            foreach ($excludeTerms as $term) {
                if (!is_string($term)) {
                    throw new \LogicException('Ein gespeicherter Ausschlussbegriff ist ungültig.');
                }
                if (str_contains($haystack, mb_strtolower($term, 'UTF-8'))) {
                    $excluded = true;
                    break;
                }
            }
            if ($excluded) {
                ++$excludedCount;
            } else {
                $acceptedItems[] = $item;
            }
        }

        $sourceUrls = array_column($acceptedItems, 'source_url');
        /** @var array{created: int<0, max>, skipped: int<0, max>} $counts */
        $counts = $this->entityManager->getConnection()->transactional(function (Connection $connection) use ($sourceUrls, $acceptedItems, $category): array {
            if ([] !== $sourceUrls) {
                $this->sourceLock->lock($connection, $sourceUrls);
            }

            $created = 0;
            $skipped = 0;
            foreach ($acceptedItems as $item) {
                if (null !== $this->videos->findBySourceUrl($item['source_url'])) {
                    ++$skipped;
                    continue;
                }

                $this->entityManager->persist(new Video(
                    $item['title'],
                    $item['description'],
                    $category,
                    VideoSourceType::Embed,
                    $item['source_url'],
                    PublishingStatus::Draft,
                    false,
                    false,
                ));
                ++$created;
            }
            $this->entityManager->flush();

            return ['created' => $created, 'skipped' => $skipped];
        });

        return sprintf(
            '%d Entwürfe angelegt, %d Duplikate übersprungen, %d wegen Ausschlussbegriffen verworfen.',
            $counts['created'],
            $counts['skipped'],
            $excludedCount,
        );
    }
}
