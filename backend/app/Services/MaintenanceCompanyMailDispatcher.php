<?php

namespace App\Services;

use App\Models\Service;
use Illuminate\Support\Facades\Log;

class MaintenanceCompanyMailDispatcher
{
    public function __construct(
        private readonly MailNotificationTemplatesService $templates,
        private readonly MailTemplatePdfService $pdfs,
        private readonly PanelNotificationMailSender $panelMail,
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

        $fileAttachments = [];
        $meta = $this->pdfs->meta(MailTemplatePdfService::KIND_MAINTENANCE_SUPPLEMENT);
        $path = $this->pdfs->absolutePath(MailTemplatePdfService::KIND_MAINTENANCE_SUPPLEMENT);
        if ($meta !== null && $path !== null && is_readable($path)) {
            $fn = basename($meta['original_filename']);
            if (! str_ends_with(strtolower($fn), '.pdf')) {
                $fn .= '.pdf';
            }
            $fileAttachments[] = [
                'path' => $path,
                'name' => $fn,
                'mime' => 'application/pdf',
            ];
        }

        try {
            $this->panelMail->sendHtml(
                $correo,
                $this->templates->maintenanceSubjectRendered($service, $company, $equipmentName),
                (string) $this->templates->maintenanceBodyHtml($service, $company, $equipmentName),
                null,
                $fileAttachments,
            );
        } catch (\Throwable $e) {
            Log::warning('maintenance_company_mail_failed', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
