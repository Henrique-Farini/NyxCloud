<?php
declare(strict_types=1);
require_once __DIR__ . '/_errors.php';
require_once __DIR__ . '/../auth/middleware.php';
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Metodo nao permitido.']); exit; }
exigirCsrfParaMutacao(); $usuario=exigirAutenticacao($pdo); $dados=json_decode(file_get_contents('php://input'),true); $dados=is_array($dados)?array_merge($_POST,$dados):$_POST;
$nome=trim((string)($dados['nome']??'')); $email=strtolower(trim((string)($dados['email']??''))); $senha=(string)($dados['senha_atual']??''); $nova=(string)($dados['nova_senha']??'');
if ($nome === '' || mb_strlen($nome)>150 || !filter_var($email,FILTER_VALIDATE_EMAIL) || mb_strlen($email)>254 || $senha==='') { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Informe nome, e-mail e senha atual validos.']); exit; }
$check=$pdo->prepare('SELECT senha_hash FROM usuario WHERE id=:id LIMIT 1'); $check->execute(['id'=>$usuario['id']]); if (!password_verify($senha,(string)$check->fetchColumn())) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Senha atual incorreta.']); exit; }
if ($nova !== '' && strlen($nova)<8) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Nova senha deve ter pelo menos 8 caracteres.']); exit; }
try { $sql='UPDATE usuario SET nome=:nome, email=:email, atualizado_em=CURRENT_TIMESTAMP'; $params=['nome'=>$nome,'email'=>$email,'id'=>$usuario['id']]; if ($nova !== '') { $sql.=', senha_hash=:senha'; $params['senha']=password_hash($nova,PASSWORD_DEFAULT); } $sql.=' WHERE id=:id'; $pdo->prepare($sql)->execute($params); }
catch (PDOException $e) { if (str_contains(strtolower($e->getMessage()),'duplicate') || str_contains(strtolower($e->getMessage()),'unique')) { http_response_code(409); echo json_encode(['success'=>false,'message'=>'Este e-mail ja esta em uso.']); exit; } throw $e; }
echo json_encode(['success'=>true,'message'=>'Perfil atualizado com sucesso.'], JSON_UNESCAPED_UNICODE);
