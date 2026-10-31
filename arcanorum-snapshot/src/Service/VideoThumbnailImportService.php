<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Video;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class VideoThumbnailImportService
{
    private const int MAX_FILE_BYTES = 4194304;

    public function __construct(
        private HttpClientInterface $httpClient,
        private VideoThumbnailUploadService $thumbnailUploads,
    ) {
    }

    /**
     * @return array{object_id: string, mime_type: string, size: int}
     */
    public function storeFromUrl(string $url, ?VideoThumbnailImportBudget $budget = null): array
    {
        if (!Video::isSafeHttpsSource($url)) {
            throw new \InvalidArgumentException('Das Vorschaubild benötigt eine sichere HTTPS-Adresse.');
        }

        if (null !== $budget) {
            $remainingBytes = $budget->remainingBytes();
            $remainingSeconds = $budget->remainingSeconds(8.0);
            if ($remainingBytes < 1 || $remainingSeconds <= 0.0) {
                throw new \InvalidArgumentException('Das gemeinsame Importbudget für Vorschaubilder ist ausgeschöpft.');
            }

            return $this->downloadAndStore(
                $url,
                min(self::MAX_FILE_BYTES, $remainingBytes),
                $remainingSeconds,
                $budget,
            );
        }

        return $this->downloadAndStore($url, self::MAX_FILE_BYTES, 8.0);
    }

    /**
     * Import a provider image only from that provider's fixed image CDN. The caller supplies the
     * remaining per-batch budget so no provider can make an import transfer more than 16 MiB.
     *
     * @return array{object_id: string, mime_type: string, size: int}
     */
    public function storeFromProviderUrl(string $provider, string $url, VideoThumbnailImportBudget $budget): array
    {
        $policy = new ProviderThumbnailUrlPolicy();
        if (!$policy->isAllowedUrl($provider, $url)) {
            throw new \InvalidArgumentException('Die Vorschaubildadresse gehört nicht zu einem freigegebenen Anbieter-CDN.');
        }
        $remainingBytes = $budget->remainingBytes();
        $remainingSeconds = $budget->remainingSeconds(4.0);
        if ($remainingBytes < 1 || $remainingSeconds <= 0.0) {
            throw new \InvalidArgumentException('Das Importbudget für Vorschaubilder ist ausgeschöpft.');
        }

        return $this->downloadAndStore(
            $url,
            min(self::MAX_FILE_BYTES, $remainingBytes),
            $remainingSeconds,
            $budget,
        );
    }

    /** @return array{object_id: string, mime_type: string, size: int} */
    private function downloadAndStore(
        string $url,
        int $maxBytes,
        float $maxDuration,
        ?VideoThumbnailImportBudget $budget = null,
    ): array {
        $response = null;
        try {
            $response = $this->httpClient->request('GET', $url, [
                'max_redirects' => 0,
                'timeout' => min(3.0, $maxDuration),
                'max_duration' => $maxDuration,
                'buffer' => false,
                'headers' => ['Accept' => 'image/jpeg, image/png, image/webp'],
            ]);
            if (200 !== $response->getStatusCode()) {
                throw new \InvalidArgumentException('Das Vorschaubild konnte über die Adresse nicht importiert werden.');
            }

            return $this->thumbnailUploads->storeContents($this->readResponse($response, $maxBytes, $budget));
        } catch (TransportExceptionInterface) {
            throw new \InvalidArgumentException('Das Vorschaubild konnte über eine sichere HTTPS-Verbindung nicht importiert werden.');
        } finally {
            $response?->cancel();
        }
    }

    private function readResponse(ResponseInterface $response, int $maxBytes, ?VideoThumbnailImportBudget $budget = null): string
    {
        $contents = '';
        foreach ($this->httpClient->stream($response, 3.0) as $chunk) {
            if ($chunk->isTimeout() || null !== $chunk->getError()) {
                throw new \InvalidArgumentException('Das Vorschaubild konnte nicht vollständig übertragen werden.');
            }
            if ($chunk->isFirst() || $chunk->isLast()) {
                continue;
            }

            $part = $chunk->getContent();
            $budget?->consumeBytes(strlen($part));
            if (strlen($part) > $maxBytes - strlen($contents)) {
                throw new \InvalidArgumentException('Vorschaubilder dürfen höchstens 4 MiB groß sein.');
            }
            $contents .= $part;
        }

        return $contents;
    }

    public function delete(string $objectId): void
    {
        $this->thumbnailUploads->delete($objectId);
    }
}
