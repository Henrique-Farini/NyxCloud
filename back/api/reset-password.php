<?php
declare(strict_types=1);
require_once __DIR__ . '/_errors.php'; require_once __DIR__ . '/../conexao.php'; require_once __DIR__ . '/../auth/audit.php';
header('Content-Type: application/json; charset=utf-8');
$dados = json_decode(file_get_contents('php://input'), true); $dados = is_array($dados) ? array_merge($_POST,$dados) : $_POST;
$token = trim((string)($dados['token'] ?? '')); $senha = (string)($dados['senha'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/',$token) || strlen($senha)<8) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Token invalido ou senha com menos de 8 caracteres.']); exit; }
$stmt=$pdo->prepare('SELECT id, usuario_id FROM senha_redefinicao WHERE token_hash=:hash AND usado_em IS NULL AND expira_em>CURRENT_TIMESTAMP LIMIT 1'); $stmt->execute(['hash'=>hash('sha256',$token)]); $reset=$stmt->fetch();
if (!$reset) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Link invalido ou expirado. Solicite outro.']); exit; }
$pdo->beginTransaction(); $pdo->prepare('UPDATE usuario SET senha_hash=:senha, atualizado_em=CURRENT_TIMESTAMP WHERE id=:id')->execute(['senha'=>password_hash($senha,PASSWORD_DEFAULT),'id'=>$reset['usuario_id']]); $pdo->prepare('UPDATE senha_redefinicao SET usado_em=CURRENT_TIMESTAMP WHERE id=:id')->execute(['id'=>$reset['id']]); $pdo->commit();
registrarAuditoriaAdministrativa($pdo, null, 'senha_redefinida', ['metodo' => 'token_email'], (int) $reset['usuario_id']);
echo json_encode(['success'=>true,'message'=>'Senha alterada. Volte ao login.'], JSON_UNESCAPED_UNICODE);
