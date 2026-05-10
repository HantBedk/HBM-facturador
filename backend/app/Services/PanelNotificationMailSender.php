<?php

namespace App\Services;

use App\MailTransport\Contracts\OutgoingMailSender;
use App\MailTransport\MailMessage;
use App\Models\AppSetting;

/**
 * Envía notificaciones del panel (bienvenida, factura, mantenimiento) por el mismo SMTP que «Enviar prueba» (PHPMailer + credenciales del panel o .env).
 */
class PanelNotificationMailSender
{
    public function __construct(
        private readonly OutgoingMailSender $outgoingMail,
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
        [$fromEmail, $fromName] = $this->resolveFrom();
        if ($fromEmail === '' || ! filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Indique un correo remitente válido en Correo del sistema o MAIL_FROM_ADDRESS en .env.');
        }
        if ($fromName === '') {
            $fromName = (string) config('app.name', 'HBM');
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

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveFrom(): array
    {
        $row = AppSetting::query()->where('key', AppSetting::KEY_MAIL_NOTIFICATIONS_FROM)->first();
        $stored = is_array($row?->value) ? $row->value : [];
        $addr = trim((string) ($stored['address'] ?? ''));
        $name = trim((string) ($stored['name'] ?? ''));

        if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL)) {
            return [$addr, $name];
        }

        return [
            trim((string) config('mail.from.address', '')),
            trim((string) config('mail.from.name', '')),
        ];
    }
}
