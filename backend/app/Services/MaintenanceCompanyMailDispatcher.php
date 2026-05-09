<?php

namespace App\Services;

use App\Mail\MaintenanceCompanyNotifyMail;
use App\Models\Service;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MaintenanceCompanyMailDispatcher
{
    public function __construct(
        private readonly MailNotificationTemplatesService $templates,
        private readonly MailTemplatePdfService $pdfs,
    ) {}

    public function sendIfApplicable(Service $service): void
    {
        if ($service->kind !== Service::KIND_MANTENIMIENTO) {
            return;
        }

        $service->loadMissing(['company', 'inventoryLot']);
        $company = $service->company;
        if ($company === null) {
            return;
        }

        $correo = strtolower(trim((string) ($company->correo ?? '')));
        if ($correo === '' || ! filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $lot = $service->inventoryLot;
        $equipmentName = $lot !== null ? trim((string) ($lot->name ?? '')) : '';
        if ($equipmentName === '') {
            $equipmentName = $lot !== null && $lot->internal_code !== null
                ? (string) $lot->internal_code
                : 'Equipo';
        }

        $attachList = [];
        $meta = $this->pdfs->meta(MailTemplatePdfService::KIND_MAINTENANCE_SUPPLEMENT);
        $path = $this->pdfs->absolutePath(MailTemplatePdfService::KIND_MAINTENANCE_SUPPLEMENT);
        if ($meta !== null && $path !== null && is_readable($path)) {
            $fn = basename($meta['original_filename']);
            if (! str_ends_with(strtolower($fn), '.pdf')) {
                $fn .= '.pdf';
            }
            $attachList[] = Attachment::fromPath($path)->as($fn)->withMime('application/pdf');
        }

        try {
            Mail::to($correo)->send(new MaintenanceCompanyNotifyMail(
                $this->templates->maintenanceSubjectRendered($service, $company, $equipmentName),
                $this->templates->maintenanceBodyHtml($service, $company, $equipmentName),
                $attachList,
            ));
        } catch (\Throwable $e) {
            Log::warning('maintenance_company_mail_failed', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
