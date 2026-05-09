<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Services\ActivityLogger;
use App\Services\AdminMailSettingsUnlockService;
use App\Mail\AdminMailNotificationsTestMail;
use App\Services\MailNotificationTemplatesService;
use App\Services\MailRuntimeSettingsService;
use App\Services\MailTemplatePdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminMailNotificationsSettingsController extends Controller
{
    public function __construct(
        private readonly MailTemplatePdfService $templatePdfs,
        private readonly MailNotificationTemplatesService $templates,
        private readonly AdminMailSettingsUnlockService $mailUnlock,
        private readonly MailRuntimeSettingsService $runtimeMail,
    ) {}

    public function unlockStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'unlocked' => $user !== null && $this->mailUnlock->isUnlocked($user),
        ]);
    }

    public function unlock(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
        ]);

        $user = $request->user();
        $this->mailUnlock->unlockWithPassword($user, $data['current_password']);

        return response()->json([
            'message' => 'Acceso concedido. Puede gestionar la configuración de correo.',
            'unlocked' => true,
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        $this->mailUnlock->assertUnlocked($request->user());

        $row = AppSetting::query()->where('key', AppSetting::KEY_MAIL_NOTIFICATIONS_FROM)->first();
        $stored = is_array($row?->value) ? $row->value : [];
        $addr = trim((string) ($stored['address'] ?? ''));
        $name = trim((string) ($stored['name'] ?? ''));
        $tpl = $this->templates->templatesForForm();

        return response()->json([
            'data' => array_merge([
                'from_address' => $addr,
                'from_name' => $name,
                'effective_from_address' => $this->effectiveAddress($addr),
                'effective_from_name' => $this->effectiveName($name),
                'company_welcome_pdf_configured' => $this->templatePdfs->configured(MailTemplatePdfService::KIND_WELCOME),
                'company_welcome_pdf_filename' => $this->templatePdfs->meta(MailTemplatePdfService::KIND_WELCOME)['original_filename'] ?? null,
                'invoice_supplement_pdf_configured' => $this->templatePdfs->configured(MailTemplatePdfService::KIND_INVOICE_SUPPLEMENT),
                'invoice_supplement_pdf_filename' => $this->templatePdfs->meta(MailTemplatePdfService::KIND_INVOICE_SUPPLEMENT)['original_filename'] ?? null,
                'maintenance_supplement_pdf_configured' => $this->templatePdfs->configured(MailTemplatePdfService::KIND_MAINTENANCE_SUPPLEMENT),
                'maintenance_supplement_pdf_filename' => $this->templatePdfs->meta(MailTemplatePdfService::KIND_MAINTENANCE_SUPPLEMENT)['original_filename'] ?? null,
                'smtp' => $this->runtimeMail->publicConfig(),
            ], $tpl),
            'help' => 'Dirección y nombre usados como remitente en correos del sistema (OTP inventario empresa, facturas enviadas, recuperación de clave, etc.). Debe coincidir con un remitente verificado en su proveedor SMTP (p. ej. Brevo). Si deja la dirección vacía, se usa MAIL_FROM_ADDRESS del .env.',
            'help_company_welcome_pdf' => 'PDF opcional para nuevas empresas (p. ej. condiciones). Al crear una empresa con correo siempre se envía el correo de bienvenida; si hay PDF configurado y legible, se adjunta. Sin PDF el envío es solo texto (el panel puede advertir antes de guardar).',
            'help_invoice_supplement_pdf' => 'PDF opcional adicional. El envío por correo siempre incluye el PDF oficial de la factura generado por el sistema; este archivo solo se añade si lo configura.',
            'help_maintenance_supplement_pdf' => 'PDF opcional al notificar mantenimiento a la empresa. El correo se envía aunque no haya PDF; si lo hay y es legible, se adjunta.',
            'help_mail_templates' => 'Texto plano (sin HTML); saltos de línea se respetan. Placeholders: bienvenida {{nombre_empresa}}, {{nombre_sistema}}; factura {{codigo_factura}}, {{nombre_empresa}}, {{mes_facturado}}, {{periodo_facturado}}, {{enlace_consulta_factura}}; mantenimiento {{nombre_empresa}}, {{nombre_equipo}}, {{codigo_servicio}}, {{tipo_servicio}}, {{fecha_mantenimiento}}, {{descripcion}}. FRONTEND_PUBLIC_URL en el servidor define el enlace de consulta.',
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->mailUnlock->assertUnlocked($request->user());

        $data = $request->validate([
            'from_address' => ['nullable', 'string', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:120'],
            'welcome_subject' => ['sometimes', 'nullable', 'string', 'max:200'],
            'welcome_body' => ['sometimes', 'nullable', 'string', 'max:8000'],
            'invoice_to_company_subject' => ['sometimes', 'nullable', 'string', 'max:200'],
            'invoice_to_company_body' => ['sometimes', 'nullable', 'string', 'max:8000'],
            'maintenance_subject' => ['sometimes', 'nullable', 'string', 'max:200'],
            'maintenance_body' => ['sometimes', 'nullable', 'string', 'max:8000'],
            'smtp_mailer' => ['sometimes', 'nullable', 'string', Rule::in(['smtp'])],
            'smtp_host' => ['sometimes', 'nullable', 'string', 'max:255'],
            'smtp_port' => ['sometimes', 'nullable', 'integer', 'between:1,65535'],
            'smtp_encryption' => ['sometimes', 'nullable', 'string', Rule::in(['tls', 'ssl', 'starttls', ''])],
            'smtp_username' => ['sometimes', 'nullable', 'string', 'max:255'],
            'smtp_password' => ['sometimes', 'nullable', 'string', 'max:255'],
            'resend_api_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'clear_smtp_password' => ['sometimes', 'boolean'],
            'clear_resend_api_key' => ['sometimes', 'boolean'],
        ]);

        $actor = $request->user();

        $addr = trim((string) ($data['from_address'] ?? ''));
        if ($addr !== '' && ! filter_var($addr, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'from_address' => ['Indique un correo electrónico válido o déjelo vacío para usar el .env.'],
            ]);
        }

        $name = trim((string) ($data['from_name'] ?? ''));

        AppSetting::setJsonValue(AppSetting::KEY_MAIL_NOTIFICATIONS_FROM, [
            'address' => $addr,
            'name' => $name,
        ]);

        $this->templates->persistPartial($data);
        $this->runtimeMail->persistFromForm($data);

        ActivityLogger::log(
            $actor,
            'correo_notificaciones_actualizado',
            'Actualizó remitente y plantillas de correo del sistema (bienvenida, factura, mantenimiento).'
        );

        return response()->json([
            'message' => 'Configuración de correo guardada.',
            'data' => $this->responseDataPayload(),
        ]);
    }

    /**
     * Envía un correo de prueba al destino indicado (mismo remitente efectivo que el resto del sistema).
     */
    public function sendTestMail(Request $request): JsonResponse
    {
        $this->mailUnlock->assertUnlocked($request->user());

        $data = $request->validate([
            'to' => ['required', 'string', 'email', 'max:255'],
        ]);

        $row = AppSetting::query()->where('key', AppSetting::KEY_MAIL_NOTIFICATIONS_FROM)->first();
        $stored = is_array($row?->value) ? $row->value : [];
        $addr = trim((string) ($stored['address'] ?? ''));
        $name = trim((string) ($stored['name'] ?? ''));
        $fromEmail = $this->effectiveAddress($addr);
        $fromName = $this->effectiveName($name);

        if ($fromEmail === '' || ! filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'to' => ['Indique un remitente válido arriba o configure MAIL_FROM_ADDRESS / MAIL_FROM_NAME en el servidor antes de probar.'],
            ]);
        }

        $to = strtolower(trim($data['to']));
        $subject = 'Prueba';
        $html = 'ok';

        try {
            Mail::to($to)->send(new AdminMailNotificationsTestMail(
                $fromEmail,
                $fromName,
                $subject,
                $html,
            ));
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug')
                    ? 'No se pudo enviar: '.$e->getMessage()
                    : 'No se pudo enviar el correo de prueba. Revise SMTP, credenciales y que el remitente esté autorizado en su proveedor.',
            ], 422);
        }

        ActivityLogger::log(
            $request->user(),
            'correo_prueba_notificaciones',
            'Envió correo de prueba de notificaciones a '.$to.'.'
        );

        return response()->json([
            'message' => 'Correo de prueba enviado a '.$to.'.',
            'sent_to' => $to,
        ]);
    }

    public function uploadTemplatePdf(Request $request): JsonResponse
    {
        $this->mailUnlock->assertUnlocked($request->user());

        $data = $request->validate([
            'kind' => ['required', 'string', Rule::in([
                MailTemplatePdfService::KIND_WELCOME,
                MailTemplatePdfService::KIND_INVOICE_SUPPLEMENT,
                MailTemplatePdfService::KIND_MAINTENANCE_SUPPLEMENT,
            ])],
            'file' => ['required', 'file', 'mimes:pdf', 'max:15360'],
        ]);

        $actor = $request->user();

        $this->templatePdfs->store($data['kind'], $request->file('file'));

        $label = match ($data['kind']) {
            MailTemplatePdfService::KIND_WELCOME => 'bienvenida empresas',
            MailTemplatePdfService::KIND_INVOICE_SUPPLEMENT => 'complemento factura',
            MailTemplatePdfService::KIND_MAINTENANCE_SUPPLEMENT => 'complemento mantenimiento',
            default => $data['kind'],
        };
        ActivityLogger::log($actor, 'correo_pdf_plantilla', 'Subió PDF de plantilla: '.$label.'.');

        return response()->json([
            'message' => 'PDF guardado.',
            'data' => $this->responseDataPayload(),
        ]);
    }

    public function deleteTemplatePdf(Request $request): JsonResponse
    {
        $this->mailUnlock->assertUnlocked($request->user());

        $data = $request->validate([
            'kind' => ['required', 'string', Rule::in([
                MailTemplatePdfService::KIND_WELCOME,
                MailTemplatePdfService::KIND_INVOICE_SUPPLEMENT,
                MailTemplatePdfService::KIND_MAINTENANCE_SUPPLEMENT,
            ])],
        ]);

        $actor = $request->user();

        $this->templatePdfs->deleteAll($data['kind']);

        ActivityLogger::log($actor, 'correo_pdf_plantilla', 'Eliminó PDF de plantilla: '.$data['kind'].'.');

        return response()->json([
            'message' => 'PDF eliminado.',
            'data' => $this->responseDataPayload(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function responseDataPayload(): array
    {
        $row = AppSetting::query()->where('key', AppSetting::KEY_MAIL_NOTIFICATIONS_FROM)->first();
        $stored = is_array($row?->value) ? $row->value : [];
        $addr = trim((string) ($stored['address'] ?? ''));
        $name = trim((string) ($stored['name'] ?? ''));

        return array_merge([
            'from_address' => $addr,
            'from_name' => $name,
            'effective_from_address' => $this->effectiveAddress($addr),
            'effective_from_name' => $this->effectiveName($name),
            'company_welcome_pdf_configured' => $this->templatePdfs->configured(MailTemplatePdfService::KIND_WELCOME),
            'company_welcome_pdf_filename' => $this->templatePdfs->meta(MailTemplatePdfService::KIND_WELCOME)['original_filename'] ?? null,
            'invoice_supplement_pdf_configured' => $this->templatePdfs->configured(MailTemplatePdfService::KIND_INVOICE_SUPPLEMENT),
            'invoice_supplement_pdf_filename' => $this->templatePdfs->meta(MailTemplatePdfService::KIND_INVOICE_SUPPLEMENT)['original_filename'] ?? null,
            'maintenance_supplement_pdf_configured' => $this->templatePdfs->configured(MailTemplatePdfService::KIND_MAINTENANCE_SUPPLEMENT),
            'maintenance_supplement_pdf_filename' => $this->templatePdfs->meta(MailTemplatePdfService::KIND_MAINTENANCE_SUPPLEMENT)['original_filename'] ?? null,
            'smtp' => $this->runtimeMail->publicConfig(),
        ], $this->templates->templatesForForm());
    }

    private function effectiveAddress(string $stored): string
    {
        if ($stored !== '' && filter_var($stored, FILTER_VALIDATE_EMAIL)) {
            return $stored;
        }

        return (string) config('mail.from.address', '');
    }

    private function effectiveName(string $stored): string
    {
        if (trim($stored) !== '') {
            return $stored;
        }

        return (string) config('mail.from.name', '');
    }
}
