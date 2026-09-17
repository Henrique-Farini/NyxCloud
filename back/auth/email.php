<?php
declare(strict_types=1);
use PHPMailer\PHPMailer\PHPMailer;
require_once dirname(__DIR__, 2) . '/PHPMailer/PHPMailer/Exception.php';
require_once dirname(__DIR__, 2) . '/PHPMailer/PHPMailer/PHPMailer.php';
require_once dirname(__DIR__, 2) . '/PHPMailer/PHPMailer/SMTP.php';

function enviarEmailRedefinicao(string $destinatario, string $nome, string $link): void
{
    $senha = (string) env('MAIL_PASSWORD', '');
    if ($senha === '') throw new RuntimeException('MAIL_PASSWORD nao configurada.');
    $mail = new PHPMailer(true);
    $mail->isSMTP(); $mail->Host = (string) env('MAIL_HOST', 'mail.nyxcloud.com.br');
    $mail->SMTPAuth = true; $mail->Username = (string) env('MAIL_USERNAME', ''); $mail->Password = $senha;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; $mail->Port = (int) env('MAIL_PORT', '587'); $mail->CharSet = 'UTF-8';
    $mail->setFrom((string) env('MAIL_FROM', env('MAIL_USERNAME', 'contato@nyxcloud.com.br')), (string) env('MAIL_FROM_NAME', 'NyxCloud'));
    $mail->addAddress($destinatario, $nome); $mail->isHTML(true); $mail->Subject = 'Redefinicao de senha | NyxCloud';
    $safeName = htmlspecialchars($nome, ENT_QUOTES, 'UTF-8'); $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
    $mail->Body = "<p>Ola, {$safeName}.</p><p><a href=\"{$safeLink}\">Redefinir minha senha</a></p><p>Link valido por 1 hora. Se nao solicitou, ignore este email.</p>";
    $mail->AltBody = "Ola, {$nome}. Acesse {$link} para redefinir sua senha. Link valido por 1 hora.";
    $mail->send();
}
