<?php

namespace App\MailTransport;

use App\MailTransport\Contracts\OutgoingMailSender;
use App\Services\MailRuntimeSettingsService;
use Illuminate\Support\Facades\Log;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/**
 * SMTP vía PHPMailer: si hay host y contraseña en panel (BD), usa eso; si no, MAIL_* del .env.
 */
final class PhpMailerSmtpTransport implements OutgoingMailSender
{
    public function __construct(
        private readonly MailRuntimeSettingsService $runtimeSmtp,
    ) {}

    public function send(MailMessage $message): void
    {
        $resolved = $this->runtimeSmtp->resolveSmtpForSend();
        $host = $resolved['host'];
        $userStr = trim($resolved['username']);
        $passStr = $resolved['password'];
        $port = $resolved['port'];
        $useImplicitTls = $resolved['useImplicitTls'];

        // Gmail muestra la clave de aplicación con espacios; al copiar a veces falla AUTH si no se normaliza.
        $passStr = preg_replace('/\s+/', '', trim($passStr)) ?? trim($passStr);
        $userStr = trim($userStr);

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->SMTPAuth = true;
        $mail->AuthType = 'LOGIN';
        $mail->Username = $userStr;
        $mail->Password = $passStr;
        $mail->Port = $port > 0 ? $port : 587;
        $mail->Timeout = 30;
        $mail->SMTPAutoTLS = true;

        if ($useImplicitTls) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        if (config('mail.smtp_ssl_relaxed')) {
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ];
        }

        if (config('mail.smtp_debug')) {
            $mail->SMTPDebug = SMTP::DEBUG_SERVER;
            $mail->Debugoutput = static function (string $str, int $level): void {
                $line = trim($str);
                if ($line !== '') {
                    Log::debug('PHPMailer SMTP: '.$line);
                }
            };
        }

        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->setFrom($message->fromEmail, $message->fromName);
        $mail->addAddress($message->toEmail);
        $mail->isHTML(true);
        $mail->Subject = $message->subject;
        $mail->Body = $message->htmlBody;

        if ($message->textBody !== null && trim($message->textBody) !== '') {
            $mail->AltBody = $message->textBody;
        } else {
            $plain = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $message->htmlBody)));
            $mail->AltBody = $plain !== '' ? $plain : ' ';
        }

        foreach ($message->fileAttachments ?? [] as $att) {
            $path = $att['path'] ?? '';
            if (! is_string($path) || $path === '' || ! is_readable($path)) {
                throw new \InvalidArgumentException('Adjunto por archivo no legible: '.(is_string($path) ? $path : ''));
            }
            $name = $att['name'] ?? basename($path);
            $mime = $att['mime'] ?? 'application/octet-stream';
            $mail->addAttachment($path, $name, PHPMailer::ENCODING_BASE64, $mime);
        }

        foreach ($message->blobAttachments ?? [] as $b) {
            $content = $b['content'] ?? '';
            $name = $b['name'] ?? 'adjunto.bin';
            $mime = $b['mime'] ?? 'application/octet-stream';
            if (! is_string($content) || $content === '') {
                throw new \InvalidArgumentException('Adjunto en memoria vacío o inválido.');
            }
            $mail->addStringAttachment($content, $name, PHPMailer::ENCODING_BASE64, $mime);
        }

        $mail->send();
    }
}
