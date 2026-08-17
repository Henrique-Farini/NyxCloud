<?php

declare(strict_types=1);

namespace NyxCloud\Lib\Acronis;

use NyxCloud\Lib\Acronis\Exceptions\CommunicationException;
use NyxCloud\Lib\Acronis\Exceptions\HttpException;
use NyxCloud\Lib\Acronis\Exceptions\JsonException;

final class HttpClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeout = 30,
        private readonly bool $sslVerify = true
    ) {
    }

    public function get(string $uri, array $query = [], array $headers = []): HttpResponse
    {
        return $this->request('GET', $uri, null, $headers, $query);
    }

    public function post(string $uri, mixed $payload = null, array $headers = [], array $query = []): HttpResponse
    {
        return $this->request('POST', $uri, $payload, $headers, $query);
    }

    public function put(string $uri, mixed $payload = null, array $headers = [], array $query = []): HttpResponse
    {
        return $this->request('PUT', $uri, $payload, $headers, $query);
    }

    public function delete(string $uri, array $query = [], array $headers = []): HttpResponse
    {
        return $this->request('DELETE', $uri, null, $headers, $query);
    }

    public function request(
        string $method,
        string $uri,
        mixed $payload = null,
        array $headers = [],
        array $query = []
    ): HttpResponse {
        if (!function_exists('curl_init')) {
            error_log('Acronis erro de comunicacao: extensao cURL ausente.');
            throw new CommunicationException('A extensao cURL do PHP nao esta habilitada.');
        }

        $url = $this->buildUrl($uri, $query);
        $responseHeaders = [];
        if ($payload !== null && !$this->hasHeader($headers, 'Content-Type')) {
            $headers['Content-Type'] = 'application/json';
        }
        $curlHeaders = $this->normalizeHeaders($headers);

        $ch = curl_init($url);
        if ($ch === false) {
            error_log('Acronis erro de comunicacao: falha ao inicializar cURL.');
            throw new CommunicationException('Falha ao inicializar cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_SSL_VERIFYPEER => $this->sslVerify,
            CURLOPT_SSL_VERIFYHOST => $this->sslVerify ? 2 : 0,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return $length;
            },
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $this->encodePayload($payload, $headers));
        }

        $body = curl_exec($ch);
        if ($body === false) {
            $message = curl_error($ch);
            curl_close($ch);
            error_log('Acronis erro de comunicacao: ' . $message);
            throw new CommunicationException('Erro de comunicacao com a API da Acronis: ' . $message);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = $this->decodeBody((string) $body, $responseHeaders);
        $response = new HttpResponse($statusCode, $responseHeaders, (string) $body, $decoded);

        if (!$response->ok()) {
            error_log(sprintf('Acronis erro HTTP: status=%d url=%s body=%s', $statusCode, $url, substr((string) $body, 0, 1000)));
            throw new HttpException('Erro HTTP retornado pela API da Acronis.', $statusCode, $decoded);
        }

        return $response;
    }

    public static function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    private function buildUrl(string $uri, array $query): string
    {
        $url = str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')
            ? $uri
            : rtrim($this->baseUrl, '/') . '/' . ltrim($uri, '/');

        if ($query !== []) {
            $separator = str_contains($url, '?') ? '&' : '?';
            $url .= $separator . http_build_query($query);
        }

        return $url;
    }

    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $key => $value) {
            $normalized[] = is_int($key) ? (string) $value : $key . ': ' . $value;
        }

        if (!$this->hasHeader($headers, 'Accept')) {
            $normalized[] = 'Accept: application/json';
        }

        return $normalized;
    }

    private function encodePayload(mixed $payload, array $headers): string
    {
        $contentType = strtolower((string) ($headers['Content-Type'] ?? $headers['content-type'] ?? 'application/json'));

        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            return is_array($payload) ? http_build_query($payload) : (string) $payload;
        }

        if (is_string($payload)) {
            return $payload;
        }

        try {
            return json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            error_log('Acronis JSON invalido ao codificar payload: ' . $e->getMessage());
            throw new JsonException('Payload JSON invalido.', 0, $e);
        }
    }

    private function decodeBody(string $body, array $headers): mixed
    {
        if ($body === '') {
            return null;
        }

        $contentType = strtolower($headers['content-type'] ?? '');
        if ($contentType !== '' && !str_contains($contentType, 'json')) {
            return $body;
        }

        try {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            error_log('Acronis JSON invalido na resposta: ' . $e->getMessage());
            throw new JsonException('Resposta JSON invalida da API da Acronis.', 0, $e);
        }
    }

    private function hasHeader(array $headers, string $name): bool
    {
        foreach ($headers as $key => $value) {
            $headerName = is_int($key) ? strtok((string) $value, ':') : (string) $key;
            if (strcasecmp((string) $headerName, $name) === 0) {
                return true;
            }
        }

        return false;
    }
}
