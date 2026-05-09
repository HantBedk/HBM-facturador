<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class InvoicePdfToCompanyMail extends Mailable
{
    /**
     * @param  non-empty-string  $invoiceCode
     * @param  non-empty-string  $companyName
     */
    public function __construct(
        public string $invoiceCode,
        public string $companyName,
        public string $pdfBinary,
        public string $attachmentFilename,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Factura '.$this->invoiceCode.' — '.$this->companyName,
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<p>Adjunto encontrará la factura en formato PDF.</p>'
                .'<p><strong>Código de factura:</strong> '.e($this->invoiceCode).'</p>'
                .'<p><strong>Empresa:</strong> '.e($this->companyName).'</p>',
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfBinary, $this->attachmentFilename)
                ->withMime('application/pdf'),
        ];
    }
}
