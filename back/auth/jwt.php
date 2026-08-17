<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

function base64UrlEncode(string $valor): string
{
    return rtrim(strtr(base64_encode($valor), '+/', '-_'), '=');
}

function base64UrlDecode(string $valor): string|false
{
    $padding = strlen($valor) % 4;
    if ($padding > 0) {
        $valor .= str_repeat('=', 4 - $padding);
    }

    return base64_decode(strtr($valor, '-_', '+/'), true);
}

function jwtSecret(): string
{
    $secret = env('JWT_SECRET');
    if ($secret === null || strlen($secret) < 32) {
        throw new RuntimeException('JWT_SECRET ausente ou fraca. Use pelo menos 32 caracteres.');
    }

    return $secret;
}

function criarTokenJwt(array $claims, ?int $ttl = null): string
{
    $agora = time();
    $ttl = max(60, $ttl ?? (int) env('JWT_TTL', '3600'));

    $payload = array_merge([
        'iss' => env('JWT_ISSUER', 'nyxcloud'),
        'iat' => $agora,
        'exp' => $agora + $ttl,
    ], $claims);

    $header = base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'HS256'], JSON_THROW_ON_ERROR));
    $body = base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
    $assinatura = base64UrlEncode(hash_hmac('sha256', $header . '.' . $body, jwtSecret(), true));

    return $header . '.' . $body . '.' . $assinatura;
}

function lerTokenJwt(string $token): ?array
{
    $partes = explode('.', $token);
    if (count($partes) !== 3) {
        return null;
    }

    [$header, $body, $assinatura] = $partes;
    $assinaturaEsperada = base64UrlEncode(hash_hmac('sha256', $header . '.' . $body, jwtSecret(), true));
    if (!hash_equals($assinaturaEsperada, $assinatura)) {
        return null;
    }

    $dados = base64UrlDecode($body);
    $payload = $dados === false ? null : json_decode($dados, true);
    if (!is_array($payload) || !isset($payload['exp']) || (int) $payload['exp'] < time()) {
        return null;
    }

    if (($payload['iss'] ?? null) !== env('JWT_ISSUER', 'nyxcloud')) {
        return null;
    }

    return $payload;
}

function definirCookieJwt(string $token, ?int $ttl = null, bool $persistente = false): void
{
    $options = [
        'path' => '/',
        'secure' => filter_var(env('COOKIE_SECURE', 'false'), FILTER_VALIDATE_BOOLEAN),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    if ($persistente) {
        $options['expires'] = time() + max(60, $ttl ?? (int) env('JWT_TTL', '3600'));
    }

    setcookie('access_token', $token, $options);
}

function limparCookieJwt(): void
{
    setcookie('access_token', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => filter_var(env('COOKIE_SECURE', 'false'), FILTER_VALIDATE_BOOLEAN),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function obterTokenDaRequisicao(): ?string
{
    return obterTokensDaRequisicao()[0] ?? null;
}

function obterTokensDaRequisicao(): array
{
    $tokens = [];

    $cabecalho = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $cabecalho, $matches)) {
        $tokens[] = trim($matches[1]);
    }

    $cookie = trim((string) ($_COOKIE['access_token'] ?? ''));
    if ($cookie !== '') {
        $tokens[] = $cookie;
    }

    return array_values(array_unique(array_filter($tokens)));
}
