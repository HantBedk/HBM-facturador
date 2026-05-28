<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\Log;

/**
 * Envío síncrono del correo de bienvenida a una empresa (usado por jobs o comandos).
 */
class CompanyWelcomeMailSender
{
    public function __construct(
        private readonly PanelNotificationMailSender $panelMail,
    ) {}

    /**
     * @return array{sent: bool, skipped_reason: string|null, to: string|null, detail: string|null}
     */
    public function sendForCompany(Company $company): array
    {
        $correo = $company->correo;
        if ($correo === null || trim($correo) === '') {
            return [
                'sent' => false,
                'skipped_reason' => 'no_correo',
                'to' => null,
                'detail' => null,
            ];
        }

        $to = strtolower(trim($correo));

        $absolutePath = null;
        $attachName = null;
        $pdf = app(MailTemplatePdfService::class);
        if ($pdf->configured(MailTemplatePdfService::KIND_WELCOME)) {
            $path = $pdf->absolutePath(MailTemplatePdfService::KIND_WELCOME);
            $meta = $pdf->meta(MailTemplatePdfService::KIND_WELCOME);
            if ($path !== null && $meta !== null && is_readable($path)) {
                $attachName = basename($meta['original_filename']);
                if (! str_ends_with(strtolower($attachName), '.pdf')) {
                    $attachName .= '.pdf';
                }
                $absolutePath = $path;
            } else {
                Log::warning('company_welcome_pdf_missing_or_unreadable', [
                    'company_id' => $company->id,
                ]);
            }
        }

        try {
            $tpl = app(MailNotificationTemplatesService::class);
            $fileAttachments = [];
            if ($absolutePath !== null && $attachName !== null && $attachName !== '') {
                $fileAttachments[] = [
                    'path' => $absolutePath,
                    'name' => $attachName,
                    'mime' => 'application/pdf',
                ];
            }
            $this->panelMail->sendHtml(
                $to,
                $tpl->welcomeSubjectRendered($company),
                (string) $tpl->welcomeBodyHtml($company),
                null,
                $fileAttachments,
            );

            return [
                'sent' => true,
                'skipped_reason' => null,
                'to' => $to,
                'detail' => null,
            ];
        } catch (\Throwable $e) {
            Log::warning('company_welcome_mail_failed', [
                'company_id' => $company->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'sent' => false,
                'skipped_reason' => 'send_failed',
                'to' => $to,
                'detail' => config('app.debug') ? $e->getMessage() : null,
            ];
        }
    }
}
