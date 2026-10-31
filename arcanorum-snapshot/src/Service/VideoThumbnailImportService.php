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
    public function storeFromUrl(string $url): array
    {
        if (!Video::isSafeHttpsSource($url)) {
            throw new \InvalidArgumentException('Das Vorschaubild benötigt eine sichere HTTPS-Adresse.');
        }

        $response = null;
        try {
            $response = $this->httpClient->request('GET', $url, [
                'max_redirects' => 0,
                'timeout' => 3.0,
                'max_duration' => 8.0,
                'buffer' => false,
                'headers' => ['Accept' => 'image/jpeg, image/png, image/webp'],
            ]);
            if (200 !== $response->getStatusCode()) {
                throw new \InvalidArgumentException('Das Vorschaubild konnte über die Adresse nicht importiert werden.');
            }

            return $this->thumbnailUploads->storeContents($this->readResponse($response));
        } catch (TransportExceptionInterface) {
            throw new \InvalidArgumentException('Das Vorschaubild konnte über eine sichere HTTPS-Verbindung nicht importiert werden.');
        } finally {
            $response?->cancel();
        }
    }

    private function readResponse(ResponseInterface $response): string
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
            if (strlen($part) > self::MAX_FILE_BYTES - strlen($contents)) {
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
