<?php

namespace App\Services;

use App\MailTransport\Contracts\OutgoingMailSender;
use App\MailTransport\MailMessage;

/**
 * Envía notificaciones del panel (bienvenida, factura, mantenimiento) por el mismo SMTP que «Enviar prueba» (PHPMailer + credenciales del panel o .env).
 */
class PanelNotificationMailSender
{
    public function __construct(
        private readonly OutgoingMailSender $outgoingMail,
        private readonly MailSenderIdentityService $mailSender,
    ) {}

    /**
     * @param  list<array{path: string, name: string, mime: string}>  $fileAttachments
     * @param  list<array{content: string, name: string, mime: string}>  $blobAttachments
     */
    public function sendHtml(
        string $toEmail,
        string $subject,
        string $htmlBody,
        ?string $textBody = null,
        array $fileAttachments = [],
        array $blobAttachments = [],
    ): void {
        $fromEmail = $this->mailSender->effectiveAddress();
        $fromName = $this->mailSender->effectiveName();
        if ($fromEmail === '' || ! filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException(
                'Indique un correo de contacto válido en Configuración → Empresa del sistema, o configure MAIL_FROM_ADDRESS en .env.'
            );
        }

        $this->outgoingMail->send(new MailMessage(
            toEmail: strtolower(trim($toEmail)),
            fromEmail: $fromEmail,
            fromName: $fromName,
            subject: $subject,
            htmlBody: $htmlBody,
            textBody: $textBody,
            fileAttachments: $fileAttachments !== [] ? $fileAttachments : null,
            blobAttachments: $blobAttachments !== [] ? $blobAttachments : null,
        ));
    }

}
