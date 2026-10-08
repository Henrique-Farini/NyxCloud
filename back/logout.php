<?php

declare(strict_types=1);

require_once __DIR__ . '/auth/jwt.php';
require_once __DIR__ . '/auth/audit.php';

try {
    require_once __DIR__ . '/conexao.php';
    foreach (obterTokensDaRequisicao() as $rawToken) {
        $payload = lerTokenJwt($rawToken);
        if ($payload !== null && ctype_digit((string) ($payload['sub'] ?? ''))) {
            $usuarioId = (int) $payload['sub'];
            invalidarTokensDoUsuario($pdo, $usuarioId);
            registrarAuditoriaAdministrativa($pdo, $usuarioId, 'logout', [], $usuarioId);
            break;
        }
    }
} catch (Throwable $e) {
    error_log('Falha ao invalidar sessao no logout: ' . $e->getMessage());
}

limparCookieJwt();

header('Cache-Control: no-store');
if (preg_match('#/logout/?$#', (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '')) === 1) {
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '/logout/';
    $base = preg_replace('#/logout/?$#', '', $path) ?: '';
    header('Location: ' . rtrim($base, '/') . '/login/?logout=1');
} else {
    header('Location: index.php?logout=1');
}
exit;
