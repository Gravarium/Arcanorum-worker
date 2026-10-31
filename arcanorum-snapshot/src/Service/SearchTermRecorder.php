<?php

declare(strict_types=1);

namespace App\Service;

use App\Platform\Security\RateLimitKeyFactory;
use App\Repository\SearchTermStatisticsRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final readonly class SearchTermRecorder
{
    public function __construct(
        private SearchTermStatisticsRepository $statistics,
        #[Autowire(service: 'limiter.search_log')]
        private RateLimiterFactory $limiter,
        private RateLimitKeyFactory $rateLimitKeys,
        private LoggerInterface $logger,
    ) {
    }

    public function record(string $term, Request $request): void
    {
        $normalized = mb_strtolower(trim($term), 'UTF-8');
        if ('' === $normalized || mb_strlen($normalized, 'UTF-8') > 100) {
            return;
        }

        $ip = $request->getClientIp();
        if (null === $ip) {
            return;
        }

        $session = $request->getSession();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $day = $now->format('Y-m-d');
        $termKey = $this->rateLimitKeys->forIdentity($normalized, 'search-term-session');
        $history = $session->get('public_search_recorded');
        $terms = is_array($history) && ($history['day'] ?? null) === $day && is_array($history['terms'] ?? null)
            ? $history['terms']
            : [];
        if (isset($terms[$termKey]) || count($terms) >= 100) {
            return;
        }

        try {
            $clientKey = $this->rateLimitKeys->forIp($request, 'search-log');
            if ($this->limiter->create($clientKey)->consume()->isAccepted()) {
                $this->statistics->record($normalized, $now);
            }
        } catch (\Exception) {
            $this->logger->warning('Eine öffentliche Suche konnte nicht für die Statistik gezählt werden.');

            return;
        }

        $terms[$termKey] = true;
        $session->set('public_search_recorded', ['day' => $day, 'terms' => $terms]);
    }
}
