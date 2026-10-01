<?php

declare(strict_types=1);

namespace NyxCloud\Lib\Acronis;

use NyxCloud\Lib\Cache\FileCache;

final class AcronisFactory
{
    public static function config(): array
    {
        return require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . 'acronis.php';
    }

    public static function configs(): array
    {
        $config = self::config();
        $ids = array_values(array_filter(array_map('trim', (array) ($config['active_ids'] ?? [])), static fn (string $value): bool => $value !== ''));
        if ($ids === []) {
            $single = self::config();
            if (($single['base_url'] ?? '') !== '' || ($single['client_id'] ?? '') !== '') {
                return [$single];
            }
            return [];
        }

        $all = [];
        foreach ((array) ($config['accounts'] ?? []) as $account) {
                if (!is_array($account) || !in_array((string) ($account['id'] ?? ''), $ids, true)) {
                    continue;
                }

                $baseUrl = rtrim((string) ($account['base_url'] ?? ''), '/');
                $clientId = (string) ($account['client_id'] ?? '');
                $secret = (string) ($account['client_secret'] ?? '');
                if ($baseUrl === '' || $clientId === '' || $secret === '') {
                    continue;
                }

                $all[] = [
                    'base_url' => $baseUrl,
                    'client_id' => $clientId,
                    'client_secret' => $secret,
                    'timeout' => (int) ($config['timeout'] ?? 30),
                    'ssl_verify' => (bool) ($config['ssl_verify'] ?? true),
                    'token_cache_ttl' => (int) ($config['token_cache_ttl'] ?? 6600),
                    'cache_path' => (string) ($config['cache_path'] ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'acronis'),
                    'endpoints' => $config['endpoints'] ?? [],
                    'cache_ttl' => $config['cache_ttl'] ?? [],
                ];
        }

        if ($all !== []) {
            return $all;
        }

        if (($config['base_url'] ?? '') !== '' && ($config['client_id'] ?? '') !== '' && ($config['client_secret'] ?? '') !== '') {
            return [$config];
        }

        return [];
    }

    public static function api(?array $config = null): AcronisApi
    {
        return new AcronisApi(self::httpClient($config), self::oauth($config));
    }

    public static function multiApi(?array $configs = null): MultiAcronisApi
    {
        $items = $configs ?? self::configs();
        $apis = [];
        foreach ($items as $config) {
            $apis[] = self::api($config);
        }

        return new MultiAcronisApi($apis);
    }

    public static function oauth(?array $config = null): OAuth
    {
        $config ??= self::config();
        $cache = new FileCache((string) $config['cache_path']);

        return new OAuth(
            self::httpClient($config),
            (string) $config['client_id'],
            (string) $config['client_secret'],
            (string) $config['endpoints']['oauth_token'],
            $cache,
            (int) $config['token_cache_ttl']
        );
    }

    public static function httpClient(?array $config = null): HttpClient
    {
        $config ??= self::config();

        return new HttpClient((string) $config['base_url'], (int) $config['timeout'], (bool) $config['ssl_verify']);
    }

    public static function cache(?array $config = null): FileCache
    {
        $config ??= self::config();

        return new FileCache((string) $config['cache_path']);
    }
}
