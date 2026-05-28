<?php

namespace App\MailTransport;

/**
 * DTO inmutable para un correo saliente (HTML). Sin dependencias de Laravel Request/HTTP.
 */
final readonly class MailMessage
{
    /**
     * @param  list<array{path: string, name: string, mime: string}>|null  $fileAttachments
     * @param  list<array{content: string, name: string, mime: string}>|null  $blobAttachments
     */
    public function __construct(
        public string $toEmail,
        public string $fromEmail,
        public string $fromName,
        public string $subject,
        public string $htmlBody,
        public ?string $textBody = null,
        public ?array $fileAttachments = null,
        public ?array $blobAttachments = null,
    ) {}
}
