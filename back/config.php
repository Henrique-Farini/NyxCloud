<?php

declare(strict_types=1);

function carregarEnv(string $arquivo): void
{
    $GLOBALS['nyxcloud_env'] = [];

    if (!is_readable($arquivo)) {
        return;
    }

    foreach (file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linha) {
        $linha = trim($linha);
        if ($linha === '' || $linha[0] === '#') {
            continue;
        }

        [$chave, $valor] = array_pad(explode('=', $linha, 2), 2, '');
        $chave = trim($chave);
        $valor = trim($valor, " \t\"'");

        if ($chave !== '') {
            // Mantem o .env isolado por requisicao; putenv vaza valores antigos no Apache threaded.
            $GLOBALS['nyxcloud_env'][$chave] = $valor;
            $_ENV[$chave] = $valor;
        }
    }
}

carregarEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

function nyxcloudCookieSecure(): bool
{
    if (filter_var(env('COOKIE_SECURE', 'false'), FILTER_VALIDATE_BOOLEAN)) {
        return true;
    }

    return strtolower((string) env('APP_ENV', 'production')) === 'production'
        || (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function nyxcloudSecurityHeaders(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if (nyxcloudCookieSecure()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

nyxcloudSecurityHeaders();

function env(string $chave, ?string $padrao = null): ?string
{
    if (array_key_exists($chave, $GLOBALS['nyxcloud_env'] ?? [])) {
        return (string) $GLOBALS['nyxcloud_env'][$chave];
    }

    $valor = getenv($chave);
    return $valor === false ? $padrao : $valor;
}
