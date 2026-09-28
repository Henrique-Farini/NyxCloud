<?php

declare(strict_types=1);

require_once __DIR__ . '/auth/jwt.php';

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
