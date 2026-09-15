<?php

declare(strict_types=1);

require_once __DIR__ . '/_errors.php';

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

verificarLimiteLogin($login);

$perfilSelect = tabelaUsuarioTemPerfil($pdo) ? 'perfil' : "'admin' AS perfil";
$stmt = $pdo->prepare(
    "SELECT id, nome, email, {$perfilSelect}, senha_hash
     FROM usuario
     WHERE ativo = TRUE
       AND (
            LOWER(email) = LOWER(:email_login)
            OR LOWER(nome) = LOWER(:nome_login)
       )
     ORDER BY id ASC
     LIMIT 1"
);
$stmt->execute(['email_login' => $login, 'nome_login' => $login]);
$usuario = $stmt->fetch();

if (!$usuario || !password_verify($senha, $usuario['senha_hash'])) {
    registrarFalhaLogin($login);
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Usuario/e-mail ou senha invalidos.']);
    exit;
}

limparFalhasLogin($login);

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
    $csrfToken = definirCookieCsrf(null, $ttl);
} catch (Throwable $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Autenticacao indisponivel.']);
    exit;
}

echo json_encode([
    'success' => true,
    'expires_in' => $ttl,
    'remembered' => $lembrar,
    'csrf_token' => $csrfToken,
    'user' => [
        'id' => (int) $usuario['id'],
        'nome' => $usuario['nome'],
        'email' => $usuario['email'],
        'perfil' => normalizarPerfil((string) ($usuario['perfil'] ?? '')),
        'perfil_nome' => nomePerfil((string) ($usuario['perfil'] ?? '')),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

function chaveLimiteLogin(string $login): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nyxcloud-login-' . hash('sha256', strtolower($ip . '|' . $login)) . '.json';
}

function lerFalhasLogin(string $login): array
{
    $file = chaveLimiteLogin($login);
    if (!is_readable($file)) {
        return [];
    }

    $payload = json_decode((string) file_get_contents($file), true);
    return is_array($payload) ? array_values(array_filter(array_map('intval', $payload))) : [];
}

function verificarLimiteLogin(string $login): void
{
    $windowStart = time() - 900;
    $falhas = array_values(array_filter(lerFalhasLogin($login), static fn (int $time): bool => $time >= $windowStart));
    if (count($falhas) < 5) {
        return;
    }

    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Muitas tentativas. Aguarde 15 minutos e tente novamente.']);
    exit;
}

function registrarFalhaLogin(string $login): void
{
    $windowStart = time() - 900;
    $falhas = array_values(array_filter(lerFalhasLogin($login), static fn (int $time): bool => $time >= $windowStart));
    $falhas[] = time();
    @file_put_contents(chaveLimiteLogin($login), json_encode($falhas, JSON_THROW_ON_ERROR), LOCK_EX);
}

function limparFalhasLogin(string $login): void
{
    $file = chaveLimiteLogin($login);
    if (is_file($file)) {
        @unlink($file);
    }
}
