<?php

namespace App\MailTransport\Contracts;

use App\MailTransport\MailMessage;

/**
 * Contrato del módulo de envío: usable desde HTTP, jobs, comandos o listeners sin acoplarse al web.
 */
interface OutgoingMailSender
{
    /**
     * Envía un mensaje ya compuesto (remitente, destino, asunto, HTML).
     *
     * @throws \InvalidArgumentException Configuración SMTP incompleta (MAIL_*).
     * @throws \PHPMailer\PHPMailer\Exception Fallo de red o SMTP (implementación PHPMailer).
     */
    public function send(MailMessage $message): void;
}
