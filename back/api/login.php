<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/../conexao.php';
    require_once __DIR__ . '/../auth/jwt.php';
} catch (Throwable $e) {
    error_log('Falha ao inicializar o login: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Serviço de autenticação indisponível. Verifique a configuração do servidor.',
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido.']);
    exit;
}

$dados = json_decode(file_get_contents('php://input'), true);
$dados = is_array($dados) ? array_merge($_POST, $dados) : $_POST;
$email = strtolower(trim((string) ($dados['email'] ?? '')));
$senha = (string) ($dados['senha'] ?? '');
$lembrar = filter_var($dados['lembrar'] ?? false, FILTER_VALIDATE_BOOLEAN);

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $senha === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Informe um e-mail válido e uma senha.']);
    exit;
}

$stmt = $pdo->prepare(
    'SELECT id, nome, email, senha_hash
     FROM usuario WHERE LOWER(email) = LOWER(:email) AND ativo = TRUE'
);
$stmt->execute(['email' => $email]);
$usuario = $stmt->fetch();

if (!$usuario || !password_verify($senha, $usuario['senha_hash'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'E-mail ou senha inválidos.']);
    exit;
}

$pdo->prepare('UPDATE usuario SET ultimo_login_em = NOW(), atualizado_em = NOW() WHERE id = :id')
    ->execute(['id' => $usuario['id']]);

try {
    $ttl = $lembrar
        ? max(3600, (int) env('JWT_REMEMBER_TTL', '2592000'))
        : max(60, (int) env('JWT_TTL', '3600'));
    $token = criarTokenJwt([
        'sub' => (string) $usuario['id'],
        'email' => $usuario['email'],
    ], $ttl);
    definirCookieJwt($token, $ttl, $lembrar);
} catch (Throwable $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Autenticação indisponível.']);
    exit;
}

echo json_encode([
    'success' => true,
    'token_type' => 'Bearer',
    'expires_in' => $ttl,
    'remembered' => $lembrar,
    'access_token' => $token,
    'user' => [
        'id' => (int) $usuario['id'],
        'nome' => $usuario['nome'],
        'email' => $usuario['email'],
    ],
]);
