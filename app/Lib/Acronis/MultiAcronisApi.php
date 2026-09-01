<?php

declare(strict_types=1);

namespace NyxCloud\Lib\Acronis;

final class MultiAcronisApi
{
    /**
     * @param list<AcronisApi> $apis
     */
    public function __construct(
        private readonly array $apis
    ) {
    }

    public function get(string $uri, array $query = [], array $headers = []): mixed
    {
        $results = [];
        foreach ($this->apis as $api) {
            try {
                $results[] = $api->get($uri, $query, $headers);
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $this->mergeResults($results);
    }

    public function post(string $uri, mixed $payload = null, array $headers = [], array $query = []): mixed
    {
        $results = [];
        foreach ($this->apis as $api) {
            try {
                $results[] = $api->post($uri, $payload, $headers, $query);
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $this->mergeResults($results);
    }

    public function put(string $uri, mixed $payload = null, array $headers = [], array $query = []): mixed
    {
        $results = [];
        foreach ($this->apis as $api) {
            try {
                $results[] = $api->put($uri, $payload, $headers, $query);
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $this->mergeResults($results);
    }

    public function delete(string $uri, array $query = [], array $headers = []): mixed
    {
        $results = [];
        foreach ($this->apis as $api) {
            try {
                $results[] = $api->delete($uri, $query, $headers);
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $this->mergeResults($results);
    }

    private function mergeResults(array $results): mixed
    {
        if ($results === []) {
            return [];
        }

        $items = [];
        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }

            if (isset($result['items']) && is_array($result['items'])) {
                $items = array_merge($items, $result['items']);
                continue;
            }

            if (isset($result['data']) && is_array($result['data'])) {
                $items = array_merge($items, $result['data']);
                continue;
            }

            if (array_is_list($result)) {
                $items = array_merge($items, $result);
            }
        }

        return $items !== [] ? ['items' => $items] : $results[0];
    }
}
