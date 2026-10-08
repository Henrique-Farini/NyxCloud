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

function nyxcloudJwtTtl(bool $remember = false): int
{
    $configured = (int) env($remember ? 'JWT_REMEMBER_TTL' : 'JWT_TTL', $remember ? '2592000' : '900');

    return $remember
        ? min(2592000, max(3600, $configured))
        : min(3600, max(300, $configured));
}

function nyxcloudCookieSameSite(): string
{
    $configured = strtolower(trim((string) env('COOKIE_SAMESITE', 'Strict')));

    return match ($configured) {
        'lax' => 'Lax',
        'strict' => 'Strict',
        default => 'Strict',
    };
}

function criarTokenJwt(array $claims, ?int $ttl = null): string
{
    $agora = time();
    $ttl = min(2592000, max(60, $ttl ?? (int) env('JWT_TTL', '3600')));

    $payload = array_merge([
        'iss' => env('JWT_ISSUER', 'nyxcloud'),
        'iat' => $agora,
        'exp' => $agora + $ttl,
        'jti' => bin2hex(random_bytes(16)),
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
        'secure' => nyxcloudCookieSecure(),
        'httponly' => true,
        'samesite' => nyxcloudCookieSameSite(),
    ];
    if ($persistente) {
        $options['expires'] = time() + min(2592000, max(60, $ttl ?? (int) env('JWT_TTL', '3600')));
    }

    setcookie('access_token', $token, $options);
}

function limparCookieJwt(): void
{
    setcookie('access_token', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => nyxcloudCookieSecure(),
        'httponly' => true,
        'samesite' => nyxcloudCookieSameSite(),
    ]);
    limparCookieCsrf();
}

function obterTokenDaRequisicao(): ?string
{
    return obterTokensDaRequisicao()[0] ?? null;
}

function obterTokensDaRequisicao(): array
{
    $tokens = [];

    // Apache may expose Authorization through REDIRECT_HTTP_AUTHORIZATION.
    $cabecalho = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $cabecalho, $matches)) {
        $tokens[] = trim($matches[1]);
    }

    $cookie = trim((string) ($_COOKIE['access_token'] ?? ''));
    if ($cookie !== '') {
        $tokens[] = $cookie;
    }

    return array_values(array_unique(array_filter($tokens)));
}

function invalidarTokensDoUsuario(PDO $pdo, int $usuarioId): void
{
    $stmt = $pdo->prepare('UPDATE usuario SET token_version = token_version + 1, atualizado_em = CURRENT_TIMESTAMP WHERE id = :id');
    $stmt->execute(['id' => $usuarioId]);
}

function criarTokenCsrf(): string
{
    return bin2hex(random_bytes(32));
}

function definirCookieCsrf(?string $token = null, ?int $ttl = null): string
{
    $token = $token !== null && $token !== '' ? $token : criarTokenCsrf();
    setcookie('csrf_token', $token, [
        'expires' => time() + max(60, $ttl ?? (int) env('JWT_REMEMBER_TTL', '2592000')),
        'path' => '/',
        'secure' => nyxcloudCookieSecure(),
        'httponly' => false,
        'samesite' => nyxcloudCookieSameSite(),
    ]);

    return $token;
}

function limparCookieCsrf(): void
{
    setcookie('csrf_token', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => nyxcloudCookieSecure(),
        'httponly' => false,
        'samesite' => nyxcloudCookieSameSite(),
    ]);
}

function tokenCsrfAtual(): string
{
    $token = trim((string) ($_COOKIE['csrf_token'] ?? ''));
    return $token !== '' ? $token : definirCookieCsrf();
}

function exigirCsrfParaMutacao(): void
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }

    $cookie = trim((string) ($_COOKIE['csrf_token'] ?? ''));
    $header = trim((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($cookie !== '' && $header !== '' && hash_equals($cookie, $header)) {
        return;
    }

    http_response_code(419);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'data' => new stdClass(),
        'meta' => new stdClass(),
        'message' => 'Token CSRF invalido ou ausente.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
