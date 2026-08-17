<?php

declare(strict_types=1);

namespace NyxCloud\Lib\Acronis;

use NyxCloud\Lib\Acronis\Exceptions\AuthenticationException;
use NyxCloud\Lib\Acronis\Exceptions\HttpException;
use NyxCloud\Lib\Cache\CacheInterface;

final class OAuth
{
    private const CACHE_KEY = 'acronis.oauth.access_token';

    private ?string $accessToken = null;
    private int $expiresAt = 0;

    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $tokenEndpoint,
        private readonly CacheInterface $cache,
        private readonly int $fallbackTtl = 6600
    ) {
    }

    public function getAccessToken(): string
    {
        if ($this->accessToken !== null && $this->expiresAt > time() + 60) {
            return $this->accessToken;
        }

        $cached = $this->cache->get(self::CACHE_KEY);
        if (is_array($cached) && !empty($cached['access_token']) && (int) ($cached['expires_at'] ?? 0) > time() + 60) {
            $this->accessToken = (string) $cached['access_token'];
            $this->expiresAt = (int) $cached['expires_at'];
            return $this->accessToken;
        }

        return $this->authenticate();
    }

    public function forgetToken(): void
    {
        error_log('Acronis token expirado ou invalidado; cache removido.');
        $this->accessToken = null;
        $this->expiresAt = 0;
        $this->cache->delete(self::CACHE_KEY);
    }

    private function authenticate(): string
    {
        if ($this->clientId === '' || $this->clientSecret === '') {
            error_log('Acronis falha de autenticacao: credenciais ausentes.');
            throw new AuthenticationException('Credenciais da Acronis nao configuradas.');
        }

        try {
            $response = $this->httpClient->post($this->tokenEndpoint, [
                'grant_type' => 'client_credentials',
            ], [
                'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ]);
        } catch (HttpException $e) {
            error_log('Acronis falha de autenticacao: HTTP ' . $e->statusCode());
            throw new AuthenticationException('Falha ao autenticar na Acronis.', 0, $e);
        }

        $payload = $response->json();
        if (!is_array($payload) || empty($payload['access_token'])) {
            error_log('Acronis falha de autenticacao: access_token ausente.');
            throw new AuthenticationException('Token de acesso ausente na resposta da Acronis.');
        }

        $ttl = isset($payload['expires_in']) ? (int) $payload['expires_in'] : $this->fallbackTtl;
        $this->expiresAt = time() + max(60, $ttl - 60);
        $this->accessToken = (string) $payload['access_token'];

        $this->cache->set(self::CACHE_KEY, [
            'access_token' => $this->accessToken,
            'expires_at' => $this->expiresAt,
        ], max(60, $ttl - 60));

        return $this->accessToken;
    }
}
