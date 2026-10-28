<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

final readonly class ProviderRequest
{
    public string $path;

    /** @var array<string, int|string|bool> */
    public array $query;

    /**
     * @param array<array-key, mixed> $query
     */
    public function __construct(
        public ProviderEndpoint $endpoint,
        array $query,
        ?string $resourceId = null,
    ) {
        $this->path = $endpoint->path($resourceId);
        $validatedQuery = [];
        foreach ($query as $key => $value) {
            if (!is_string($key)
                || (!is_int($value) && !is_string($value) && !is_bool($value))) {
                throw new \InvalidArgumentException('The provider request parameters are invalid.');
            }
            if (1 !== preg_match('/\A[a-zA-Z][a-zA-Z0-9_]*\z/D', $key)
                || (is_string($value)
                    && (strlen($value) > 2048 || 1 === preg_match('/[\x00-\x1F\x7F]/', $value)))) {
                throw new \InvalidArgumentException('The provider request parameters are invalid.');
            }
            $validatedQuery[$key] = $value;
        }

        /** @var array<string, int|string|bool> $validatedQuery */
        $this->query = $validatedQuery;
    }

    public function uri(): string
    {
        $baseUri = $this->endpoint->provider()->apiBaseUri();

        return $baseUri . $this->path . ('' === $this->queryString() ? '' : '?' . $this->queryString());
    }

    private function queryString(): string
    {
        return http_build_query($this->query, '', '&', PHP_QUERY_RFC3986);
    }
}
