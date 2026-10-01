<?php

declare(strict_types=1);

namespace NyxCloud\Lib\Acronis;

use NyxCloud\Lib\Acronis\Exceptions\CommunicationException;

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
        $errors = [];
        foreach ($this->apis as $api) {
            try {
                $results[] = $api->get($uri, $query, $headers);
            } catch (\Throwable $e) {
                $errors[] = $e;
            }
        }

        return $this->mergeResults($results, $errors);
    }

    public function post(string $uri, mixed $payload = null, array $headers = [], array $query = []): mixed
    {
        $results = [];
        $errors = [];
        foreach ($this->apis as $api) {
            try {
                $results[] = $api->post($uri, $payload, $headers, $query);
            } catch (\Throwable $e) {
                $errors[] = $e;
            }
        }

        return $this->mergeResults($results, $errors);
    }

    public function put(string $uri, mixed $payload = null, array $headers = [], array $query = []): mixed
    {
        $results = [];
        $errors = [];
        foreach ($this->apis as $api) {
            try {
                $results[] = $api->put($uri, $payload, $headers, $query);
            } catch (\Throwable $e) {
                $errors[] = $e;
            }
        }

        return $this->mergeResults($results, $errors);
    }

    public function delete(string $uri, array $query = [], array $headers = []): mixed
    {
        $results = [];
        $errors = [];
        foreach ($this->apis as $api) {
            try {
                $results[] = $api->delete($uri, $query, $headers);
            } catch (\Throwable $e) {
                $errors[] = $e;
            }
        }

        return $this->mergeResults($results, $errors);
    }

    private function mergeResults(array $results, array $errors = []): mixed
    {
        if ($results === []) {
            if ($errors !== []) {
                $lastError = $errors[array_key_last($errors)];
                error_log('Acronis: todas as contas configuradas estao indisponiveis. ' . $lastError->getMessage());
                throw new CommunicationException('As contas Acronis configuradas estao indisponiveis no momento.');
            }

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
