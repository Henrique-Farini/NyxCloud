<?php
declare(strict_types=1);
require_once __DIR__ . '/_errors.php'; require_once __DIR__ . '/../auth/middleware.php';
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Metodo nao permitido.']); exit; }
exigirCsrfParaMutacao(); $usuario=exigirAutenticacao($pdo); $dados=json_decode(file_get_contents('php://input'),true); $dados=is_array($dados)?array_merge($_POST,$dados):$_POST;
$atual=(string)($dados['senha_atual']??''); $nova=(string)($dados['nova_senha']??''); $stmt=$pdo->prepare('SELECT senha_hash FROM usuario WHERE id=:id LIMIT 1'); $stmt->execute(['id'=>$usuario['id']]);
if (!password_verify($atual,(string)$stmt->fetchColumn())) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Senha atual incorreta.']); exit; }
if (strlen($nova)<8) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Nova senha deve ter pelo menos 8 caracteres.']); exit; }
$pdo->prepare('UPDATE usuario SET senha_hash=:senha, atualizado_em=CURRENT_TIMESTAMP WHERE id=:id')->execute(['senha'=>password_hash($nova,PASSWORD_DEFAULT),'id'=>$usuario['id']]);
echo json_encode(['success'=>true,'message'=>'Senha alterada com sucesso.'], JSON_UNESCAPED_UNICODE);
