<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\HtmlString;

/** Correo de diagnóstico SMTP/remitente desde el panel de notificaciones (solo admin). */
class AdminMailNotificationsTestMail extends Mailable
{
    public function __construct(
        public string $fromAddress,
        public string $fromDisplayName,
        public string $subjectLine,
        public string $htmlBody,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddress, $this->fromDisplayName),
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: new HtmlString($this->htmlBody),
        );
    }
}
