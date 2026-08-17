<?php

declare(strict_types=1);

namespace NyxCloud\Lib\Acronis;

use NyxCloud\Lib\Acronis\Exceptions\HttpException;

final class AcronisApi
{
    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly OAuth $oauth
    ) {
    }

    public function get(string $uri, array $query = [], array $headers = []): mixed
    {
        return $this->send('GET', $uri, null, $headers, $query);
    }

    public function post(string $uri, mixed $payload = null, array $headers = [], array $query = []): mixed
    {
        return $this->send('POST', $uri, $payload, $headers, $query);
    }

    public function put(string $uri, mixed $payload = null, array $headers = [], array $query = []): mixed
    {
        return $this->send('PUT', $uri, $payload, $headers, $query);
    }

    public function delete(string $uri, array $query = [], array $headers = []): mixed
    {
        return $this->send('DELETE', $uri, null, $headers, $query);
    }

    private function send(string $method, string $uri, mixed $payload, array $headers, array $query): mixed
    {
        $headers = array_merge($headers, HttpClient::bearer($this->oauth->getAccessToken()));

        try {
            return $this->httpClient->request($method, $uri, $payload, $headers, $query)->json();
        } catch (HttpException $e) {
            if ($e->statusCode() !== 401) {
                throw $e;
            }

            $this->oauth->forgetToken();
            $headers['Authorization'] = 'Bearer ' . $this->oauth->getAccessToken();

            return $this->httpClient->request($method, $uri, $payload, $headers, $query)->json();
        }
    }
}

