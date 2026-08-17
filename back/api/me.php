<?php

declare(strict_types=1);

require_once __DIR__ . '/../auth/middleware.php';

header('Content-Type: application/json; charset=utf-8');
$usuario = exigirAutenticacao($pdo);

echo json_encode(['success' => true, 'user' => $usuario]);

