<?php

namespace App\Mail;

use App\Models\Company;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\HtmlString;

class CompanyWelcomeMail extends Mailable
{
    /**
     * @param  non-empty-string|null  $absolutePdfPath  Ruta absoluta al PDF opcional de bienvenida.
     * @param  non-empty-string|null  $attachmentFilename  Nombre del adjunto; null si no hay PDF.
     */
    public function __construct(
        public Company $company,
        public ?string $absolutePdfPath,
        public ?string $attachmentFilename,
        public string $subjectLine,
        public HtmlString $htmlBody,
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
        if ($this->absolutePdfPath === null || $this->attachmentFilename === null || $this->attachmentFilename === '') {
            return [];
        }

        return [
            Attachment::fromPath($this->absolutePdfPath)
                ->as($this->attachmentFilename)
                ->withMime('application/pdf'),
        ];
    }
}
