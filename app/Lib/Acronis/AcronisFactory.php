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

    public static function api(?array $config = null): AcronisApi
    {
        return new AcronisApi(self::httpClient($config), self::oauth($config));
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
