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

if ($nome === '' || $mensagem === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Preencha nome, e-mail válido e mensagem.']);
    exit;
}

$corpo = "Nome: $nome\n";
$corpo .= "E-mail: $email\n";
$corpo .= "Empresa: $empresa\n\n";
$corpo .= "Mensagem:\n$mensagem";

$mail = new PHPMailer(true);

try {

    $mail->isSMTP();
    $mail->Host       = env('MAIL_HOST', 'mail.nyxcloud.com.br');
    $mail->SMTPAuth   = true;
    $mail->Username   = env('MAIL_USERNAME', '');
    $mail->Password   = env('MAIL_PASSWORD', '');
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

    echo json_encode([
        'success' => false,
        'message' => 'Não foi possível enviar a mensagem agora.'
    ]);

}
