<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/../conexao.php';
    require_once __DIR__ . '/../auth/jwt.php';
    require_once __DIR__ . '/../auth/middleware.php';
} catch (Throwable $e) {
    error_log('Falha ao inicializar o login: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Servico de autenticacao indisponivel. Verifique a configuracao do servidor.',
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo nao permitido.']);
    exit;
}

$dados = json_decode(file_get_contents('php://input'), true);
$dados = is_array($dados) ? array_merge($_POST, $dados) : $_POST;
$login = trim((string) ($dados['email'] ?? ''));
$senha = (string) ($dados['senha'] ?? '');
$lembrar = filter_var($dados['lembrar'] ?? false, FILTER_VALIDATE_BOOLEAN);

if ($login === '' || $senha === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Informe seu e-mail ou usuario e uma senha.']);
    exit;
}

$perfilSelect = tabelaUsuarioTemPerfil($pdo) ? 'perfil' : "'admin' AS perfil";
$stmt = $pdo->prepare(
    "SELECT id, nome, email, {$perfilSelect}, senha_hash
     FROM usuario
     WHERE ativo = TRUE
       AND (
            LOWER(email) = LOWER(:login)
            OR LOWER(nome) = LOWER(:login)
       )
     ORDER BY id ASC
     LIMIT 1"
);
$stmt->execute(['login' => $login]);
$usuario = $stmt->fetch();

if (!$usuario || !password_verify($senha, $usuario['senha_hash'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Usuario/e-mail ou senha invalidos.']);
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
    echo json_encode(['success' => false, 'message' => 'Autenticacao indisponivel.']);
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
        'perfil' => normalizarPerfil((string) ($usuario['perfil'] ?? '')),
        'perfil_nome' => nomePerfil((string) ($usuario['perfil'] ?? '')),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
