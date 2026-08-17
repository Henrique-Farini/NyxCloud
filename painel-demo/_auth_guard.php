<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/back/auth/middleware.php';

$usuarioPainel = usuarioAutenticado($pdo);
if ($usuarioPainel === null) {
    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $panelPath = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/painel-demo/index.php')));
    $projectPath = rtrim(str_replace('\\', '/', dirname($panelPath)), '/');
    $loginPath = ($projectPath === '' ? '' : $projectPath) . '/back/index.php';

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Location: ' . $loginPath . '?next=' . rawurlencode($requestUri), true, 302);
    exit;
}

header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Vary: Cookie');

