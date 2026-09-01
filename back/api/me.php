<?php

declare(strict_types=1);

require_once __DIR__ . '/../auth/middleware.php';

header('Content-Type: application/json; charset=utf-8');
$usuario = exigirAutenticacao($pdo);
$perfil = normalizarPerfil((string) ($usuario['perfil'] ?? ''));
$usuario['perfil'] = $perfil;
$usuario['perfil_nome'] = nomePerfil($perfil);
$usuario['pode_gerenciar_contas'] = usuarioPodeGerenciarContas($usuario);
$usuario['csrf_token'] = tokenCsrfAtual();

echo json_encode([
    'success' => true,
    'user' => $usuario,
    'data' => $usuario,
    'meta' => new stdClass(),
    'message' => '',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
