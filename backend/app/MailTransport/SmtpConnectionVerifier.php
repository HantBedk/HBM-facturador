<?php

namespace App\MailTransport;

use Illuminate\Support\Facades\Log;
use PHPMailer\PHPMailer\Exception as PhpMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/**
 * Comprueba usuario/clave contra el servidor SMTP sin enviar correo (smtpConnect + AUTH).
 */
class SmtpConnectionVerifier
{
    /**
     * @param  array{host: string, port: int, username: string, password: string, useImplicitTls: bool}  $cred
     * @return bool true si se llegó a comprobar AUTH con el servidor; false si la verificación está desactivada (p. ej. tests).
     *
     * @throws PhpMailerException
     */
    public function verify(array $cred): bool
    {
        if (! config('mail.verify_smtp_on_save', true)) {
            return false;
        }

        $password = preg_replace('/\s+/', '', trim($cred['password'])) ?? trim($cred['password']);
        $username = trim($cred['username']);

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->SMTPAuth = true;
        $mail->AuthType = 'LOGIN';
        $mail->Host = $cred['host'];
        $mail->Port = $cred['port'] > 0 ? $cred['port'] : 587;
        $mail->Username = $username;
        $mail->Password = $password;
        $mail->Timeout = 20;
        $mail->SMTPAutoTLS = true;

        if ($cred['useImplicitTls']) {
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
                    Log::debug('PHPMailer verify: '.$line);
                }
            };
        }

        try {
            if (! $mail->smtpConnect()) {
                throw new PhpMailerException('No se pudo conectar al servidor SMTP.');
            }
        } finally {
            $mail->smtpClose();
        }

        return true;
    }
}
