<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\HtmlString;

class MaintenanceCompanyNotifyMail extends Mailable
{
    /**
     * @param  array<int, Attachment>  $attachmentsList
     */
    public function __construct(
        public string $subjectLine,
        public HtmlString $htmlBody,
        public array $attachmentsList,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->htmlBody,
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return $this->attachmentsList;
    }
}
