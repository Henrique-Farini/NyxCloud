<?php
declare(strict_types=1);
require_once __DIR__ . '/_errors.php'; require_once __DIR__ . '/../conexao.php'; require_once __DIR__ . '/../auth/email.php';
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Metodo nao permitido.']); exit; }
$dados = json_decode(file_get_contents('php://input'), true); $email = strtolower(trim((string) (($dados['email'] ?? $_POST['email'] ?? ''))));
$generic = ['success'=>true,'message'=>'Se o e-mail existir, enviaremos instrucoes para redefinir a senha.'];
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { echo json_encode($generic, JSON_UNESCAPED_UNICODE); exit; }
$stmt = $pdo->prepare('SELECT id, nome, email FROM usuario WHERE ativo = TRUE AND LOWER(email) = LOWER(:email) LIMIT 1'); $stmt->execute(['email'=>$email]); $usuario = $stmt->fetch();
if (!$usuario) { echo json_encode($generic, JSON_UNESCAPED_UNICODE); exit; }
$token = bin2hex(random_bytes(32));
$pdo->prepare('DELETE FROM senha_redefinicao WHERE usuario_id = :id OR expira_em < CURRENT_TIMESTAMP')->execute(['id'=>$usuario['id']]);
$expiraSql = (($databaseDriver ?? env('DB_CONNECTION', 'pgsql')) === 'mysql') ? 'DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 1 HOUR)' : "CURRENT_TIMESTAMP + INTERVAL '1 hour'";
$pdo->prepare("INSERT INTO senha_redefinicao (usuario_id, token_hash, expira_em) VALUES (:id, :hash, {$expiraSql})")->execute(['id'=>$usuario['id'],'hash'=>hash('sha256',$token)]);
$base = rtrim((string) env('APP_URL', ''), '/');
if ($base === '') $base = rtrim((string) ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http').'://'.($_SERVER['HTTP_HOST'] ?? 'localhost').dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');
try { enviarEmailRedefinicao((string)$usuario['email'], (string)$usuario['nome'], $base.'/redefinir-senha.php?token='.rawurlencode($token)); }
catch (Throwable $e) { error_log('Falha no email de redefinicao: '.$e->getMessage()); http_response_code(503); echo json_encode(['success'=>false,'message'=>'Nao foi possivel enviar o email agora.']); exit; }
echo json_encode($generic, JSON_UNESCAPED_UNICODE);
