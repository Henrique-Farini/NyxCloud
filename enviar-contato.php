<?php

header('Content-Type: application/json');

require_once __DIR__ . '/back/config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/PHPMailer/PHPMailer/Exception.php';
require __DIR__ . '/PHPMailer/PHPMailer/PHPMailer.php';
require __DIR__ . '/PHPMailer/PHPMailer/SMTP.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido.']);
    exit;
}

$nome = trim((string) ($_POST['nome'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$empresa = trim((string) ($_POST['empresa'] ?? ''));
$mensagem = trim((string) ($_POST['mensagem'] ?? ''));

if (
    $nome === ''
    || $mensagem === ''
    || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || mb_strlen($nome) > 150
    || mb_strlen($email) > 254
    || mb_strlen($empresa) > 150
    || mb_strlen($mensagem) > 5000
) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Preencha os campos corretamente e mantenha a mensagem com até 5000 caracteres.']);
    exit;
}

$rateFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nyxcloud-contact-' . hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')) . '.json';
$now = time();
$recent = [];
if (is_readable($rateFile)) {
    $stored = json_decode((string) file_get_contents($rateFile), true);
    $recent = is_array($stored) ? array_values(array_filter(array_map('intval', $stored), static fn (int $time): bool => $time >= $now - 3600)) : [];
}
if (count($recent) >= 5) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Muitas mensagens em pouco tempo. Tente novamente mais tarde.']);
    exit;
}
@file_put_contents($rateFile, json_encode([...$recent, $now], JSON_THROW_ON_ERROR), LOCK_EX);

$corpo = "Nome: $nome\n";
$corpo .= "E-mail: $email\n";
$corpo .= "Empresa: $empresa\n\n";
$corpo .= "Mensagem:\n$mensagem";

$mail = new PHPMailer(true);

try {

    $mailPassword = (string) env('MAIL_PASSWORD', '');
    if ($mailPassword === '') {
        error_log('Formulario de contato indisponivel: MAIL_PASSWORD nao configurada.');
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'O envio de mensagens está temporariamente indisponível.']);
        exit;
    }

    $mail->isSMTP();
    $mail->Host       = env('MAIL_HOST', 'mail.nyxcloud.com.br');
    $mail->SMTPAuth   = true;
    $mail->Username   = env('MAIL_USERNAME', '');
    $mail->Password   = $mailPassword;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = (int) env('MAIL_PORT', '587');
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom('contato@nyxcloud.com.br', 'Site NyxCloud');
    $mail->addAddress(env('MAIL_TO', 'contato@nyxcloud.com.br'));
    $mail->addReplyTo($email, $nome);

    $mail->isHTML(false);
    $mail->Subject = 'Novo contato pelo site NyxCloud';
    $mail->Body    = $corpo;

    $mail->send();

    echo json_encode([
        'success' => true,
        'message' => 'Mensagem enviada com sucesso!'
    ]);

} catch (Exception $e) {

    error_log('Falha no formulario de contato: ' . $e->getMessage());
    http_response_code(502);

    echo json_encode([
        'success' => false,
        'message' => 'Não foi possível enviar a mensagem agora.'
    ]);

}
