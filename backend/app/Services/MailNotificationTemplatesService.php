<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Service;
use Illuminate\Support\HtmlString;

class MailNotificationTemplatesService
{
    public const PH_COMPANY = '{{nombre_empresa}}';

    public const PH_INVOICE_CODE = '{{codigo_factura}}';

    public const PH_ENLACE_FACTURA = '{{enlace_consulta_factura}}';

    public const PH_MES_FACTURADO = '{{mes_facturado}}';

    public const PH_PERIODO_FACTURADO = '{{periodo_facturado}}';

    public const PH_CODIGO_SERVICIO = '{{codigo_servicio}}';

    public const PH_NOMBRE_EQUIPO = '{{nombre_equipo}}';

    public const PH_TIPO_SERVICIO = '{{tipo_servicio}}';

    public const PH_FECHA_MANTENIMIENTO = '{{fecha_mantenimiento}}';

    public const PH_DESCRIPCION = '{{descripcion}}';

    public const PH_NOMBRE_SISTEMA = '{{nombre_sistema}}';

    public const DEFAULT_WELCOME_SUBJECT = 'Bienvenidos a {{nombre_sistema}} {{nombre_empresa}}';

    public const DEFAULT_WELCOME_BODY = "Hola {{nombre_empresa}},\n\n"
        ."Es un gusto darles la bienvenida a {{nombre_sistema}}.\n\n"
        ."A partir de este momento, cuentan con nuestro respaldo como su aliado estratégico en mantenimiento, soporte técnico y gestión operativa, con un enfoque basado en atención oportuna, procesos claros y acompañamiento constante.\n\n"
        ."Nuestro compromiso es brindar soluciones ágiles, seguimiento responsable y una comunicación directa que les permita operar con tranquilidad y confianza, sabiendo que tendrán un equipo disponible cuando lo necesiten.\n\n"
        ."Adjunto a este correo encontrarán el documento PDF con las condiciones generales del servicio, el alcance de nuestra gestión y los lineamientos establecidos para garantizar transparencia, organización y previsibilidad en nuestra relación comercial.\n\n"
        ."Este correo electrónico, junto con esta dirección de contacto, será nuestro canal oficial de comunicación. Para cualquier solicitud, novedad o inquietud, pueden responder directamente a este mismo mensaje y atenderemos su requerimiento con la prioridad correspondiente.\n\n"
        ."Agradecemos la confianza depositada en {{nombre_sistema}} y esperamos construir una relación sólida, eficiente y de largo plazo.\n\n"
        ."Cordialmente,\n"
        .'Equipo de {{nombre_sistema}}';

    public const DEFAULT_INVOICE_SUBJECT = 'Factura {{codigo_factura}} — {{mes_facturado}}';

    public const DEFAULT_INVOICE_BODY = "Hola {{nombre_empresa}},\n"
        ."Esperamos que se encuentren muy bien.\n"
        ."Queremos agradecerles por confiar en nuestros servicios y permitirnos ser parte del soporte y crecimiento tecnológico de su empresa.\n"
        ."Por medio de este correo les enviamos la factura correspondiente al período de {{mes_facturado}} ({{periodo_facturado}}).\n\n"
        ."Información de la factura\n"
        ."Código de factura: {{codigo_factura}}\n"
        ."Empresa: {{nombre_empresa}}\n\n"
        ."Adjunto a este correo encontrarán el PDF oficial de la factura, donde podrán revisar de forma detallada los servicios prestados, valores correspondientes y toda la información relacionada con la facturación del período.\n"
        ."En caso de que el archivo PDF presente inconvenientes para abrirse, también podrán consultar, visualizar y descargar la factura directamente desde nuestra página web utilizando el número de factura indicado anteriormente o ingresando al siguiente enlace:\n"
        ."{{enlace_consulta_factura}}\n\n"
        ."Este acceso les permitirá validar toda la información de forma rápida y segura.\n"
        ."Agradecemos nuevamente su confianza y preferencia.\n"
        ."Para cualquier duda, soporte o aclaración sobre la factura, pueden responder directamente a este mismo correo y con gusto les atenderemos.\n\n"
        ."Cordialmente,\n"
        .'Equipo de {{nombre_sistema}}';

    public const DEFAULT_MAINTENANCE_SUBJECT = 'Mantenimiento registrado — {{nombre_equipo}} ({{nombre_empresa}})';

    public const DEFAULT_MAINTENANCE_BODY = "Hola {{nombre_empresa}},\n\n"
        ."Le informamos que se registró un mantenimiento al equipo bajo su custodia.\n\n"
        ."Empresa: {{nombre_empresa}}\n"
        ."Equipo: {{nombre_equipo}}\n"
        ."Referencia de servicio: {{codigo_servicio}}\n"
        ."Tipo: {{tipo_servicio}}\n"
        ."Fecha: {{fecha_mantenimiento}}\n\n"
        ."Detalle del trabajo:\n{{descripcion}}\n\n"
        ."Si aplica, adjuntamos documentación complementaria al correo.\n\n"
        ."Para cualquier consulta puede responder a este mismo mensaje.\n\n"
        ."Cordialmente,\n"
        .'Equipo de {{nombre_sistema}}';

    /**
     * @return array<string, string>
     */
    public function templatesForForm(): array
    {
        $raw = $this->rawTemplates();

        return [
            'welcome_subject' => $this->coalesceTrim($raw['welcome_subject'] ?? null, self::DEFAULT_WELCOME_SUBJECT),
            'welcome_body' => $this->coalesceTrim($raw['welcome_body'] ?? null, self::DEFAULT_WELCOME_BODY),
            'invoice_to_company_subject' => $this->coalesceTrim($raw['invoice_to_company_subject'] ?? null, self::DEFAULT_INVOICE_SUBJECT),
            'invoice_to_company_body' => $this->coalesceTrim($raw['invoice_to_company_body'] ?? null, self::DEFAULT_INVOICE_BODY),
            'maintenance_subject' => $this->coalesceTrim($raw['maintenance_subject'] ?? null, self::DEFAULT_MAINTENANCE_SUBJECT),
            'maintenance_body' => $this->coalesceTrim($raw['maintenance_body'] ?? null, self::DEFAULT_MAINTENANCE_BODY),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function persistPartial(array $validated): void
    {
        $cur = $this->rawTemplates();
        $pick = function (string $key) use ($validated, $cur): string {
            return array_key_exists($key, $validated)
                ? (string) $validated[$key]
                : (string) ($cur[$key] ?? '');
        };

        AppSetting::setJsonValue(AppSetting::KEY_MAIL_NOTIFICATION_TEMPLATES, [
            'welcome_subject' => $this->sanitizeOneLine($pick('welcome_subject')),
            'welcome_body' => $this->sanitizeMultiline($pick('welcome_body')),
            'invoice_to_company_subject' => $this->sanitizeOneLine($pick('invoice_to_company_subject')),
            'invoice_to_company_body' => $this->sanitizeMultiline($pick('invoice_to_company_body')),
            'maintenance_subject' => $this->sanitizeOneLine($pick('maintenance_subject')),
            'maintenance_body' => $this->sanitizeMultiline($pick('maintenance_body')),
        ]);
    }

    public function invoicePublicConsultUrl(string $invoiceCode): string
    {
        $base = config('hbm.frontend_public_url', 'http://localhost:5173');

        return $base.'/consulta-factura?codigo='.rawurlencode($invoiceCode);
    }

    public function welcomeSubjectRendered(Company $company): string
    {
        return $this->renderSubject($this->effectiveWelcomeSubject(), [
            self::PH_COMPANY => $company->nombre,
            self::PH_NOMBRE_SISTEMA => $this->nombreSistemaParaPlaceholders(),
        ]);
    }

    public function welcomeBodyHtml(Company $company): HtmlString
    {
        return $this->renderBodyHtml($this->effectiveWelcomeBody(), [
            self::PH_COMPANY => $company->nombre,
            self::PH_NOMBRE_SISTEMA => $this->nombreSistemaParaPlaceholders(),
        ]);
    }

    /**
     * Valor para {{nombre_sistema}} en bienvenida, factura y mantenimiento:
     * nombre comercial o razón social (Empresa sistema), si no el nombre comercial del remitente en Correo del sistema.
     */
    public function nombreSistemaParaPlaceholders(): string
    {
        $org = app(SystemOrganizationProfileService::class)->displayNameForMail();
        if ($org !== '') {
            return $org;
        }

        $panelName = $this->mailNotificationsStoredFromDisplayName();
        if ($panelName !== '') {
            return $panelName;
        }

        return '';
    }

    private function mailNotificationsStoredFromDisplayName(): string
    {
        $row = AppSetting::query()->where('key', AppSetting::KEY_MAIL_NOTIFICATIONS_FROM)->first();
        if ($row === null || ! is_array($row->value)) {
            return '';
        }

        return trim((string) ($row->value['name'] ?? ''));
    }

    public function invoiceToCompanySubjectRendered(Invoice $invoice, string $companyName): string
    {
        return $this->renderSubject($this->effectiveInvoiceSubject(), [
            self::PH_INVOICE_CODE => $invoice->code,
            self::PH_COMPANY => $companyName,
            self::PH_MES_FACTURADO => $this->mesFacturado($invoice),
            self::PH_PERIODO_FACTURADO => $this->periodoFacturado($invoice),
            self::PH_NOMBRE_SISTEMA => $this->nombreSistemaParaPlaceholders(),
        ]);
    }

    public function invoiceToCompanyBodyHtml(Invoice $invoice, string $companyName, string $consultUrl): HtmlString
    {
        $tpl = $this->effectiveInvoiceBody();
        $map = [
            self::PH_INVOICE_CODE => e($invoice->code),
            self::PH_COMPANY => e($companyName),
            self::PH_MES_FACTURADO => e($this->mesFacturado($invoice)),
            self::PH_PERIODO_FACTURADO => e($this->periodoFacturado($invoice)),
            self::PH_ENLACE_FACTURA => '<a href="'.e($consultUrl).'" rel="noopener noreferrer">'.e($consultUrl).'</a>',
            self::PH_NOMBRE_SISTEMA => e($this->nombreSistemaParaPlaceholders()),
        ];
        $out = $tpl;
        foreach ($map as $needle => $value) {
            $out = str_replace($needle, $value, $out);
        }

        return new HtmlString(nl2br($out, false));
    }

    public function maintenanceSubjectRendered(Service $service, Company $company, string $equipmentName): string
    {
        return $this->renderSubject($this->effectiveMaintenanceSubject(), $this->maintenanceReplacementsPlain($service, $company, $equipmentName));
    }

    public function maintenanceBodyHtml(Service $service, Company $company, string $equipmentName): HtmlString
    {
        return $this->renderBodyHtml($this->effectiveMaintenanceBody(), $this->maintenanceReplacementsPlain($service, $company, $equipmentName));
    }

    /**
     * @return array<string, string>
     */
    private function maintenanceReplacementsPlain(Service $service, Company $company, string $equipmentName): array
    {
        return [
            self::PH_COMPANY => $company->nombre,
            self::PH_NOMBRE_SISTEMA => $this->nombreSistemaParaPlaceholders(),
            self::PH_CODIGO_SERVICIO => (string) $service->code,
            self::PH_NOMBRE_EQUIPO => $equipmentName,
            self::PH_TIPO_SERVICIO => (string) $service->service_type,
            self::PH_FECHA_MANTENIMIENTO => (string) $service->service_date,
            self::PH_DESCRIPCION => (string) $service->description,
        ];
    }

    private function mesFacturado(Invoice $invoice): string
    {
        $m = (int) $invoice->period_month;
        $y = (int) $invoice->period_year;
        $meses = [
            1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
            5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
            9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
        ];
        $label = $meses[$m] ?? (string) $m;

        return $label.' '.$y;
    }

    private function periodoFacturado(Invoice $invoice): string
    {
        return sprintf('%02d/%d', (int) $invoice->period_month, (int) $invoice->period_year);
    }

    /**
     * @return array<string, mixed>
     */
    public function rawTemplates(): array
    {
        $row = AppSetting::query()->where('key', AppSetting::KEY_MAIL_NOTIFICATION_TEMPLATES)->first();
        if ($row === null || ! is_array($row->value)) {
            return [];
        }

        return $row->value;
    }

    private function effectiveWelcomeSubject(): string
    {
        $t = trim((string) ($this->rawTemplates()['welcome_subject'] ?? ''));

        return $t !== '' ? $t : self::DEFAULT_WELCOME_SUBJECT;
    }

    private function effectiveWelcomeBody(): string
    {
        $t = trim((string) ($this->rawTemplates()['welcome_body'] ?? ''));

        return $t !== '' ? $t : self::DEFAULT_WELCOME_BODY;
    }

    private function effectiveInvoiceSubject(): string
    {
        $t = trim((string) ($this->rawTemplates()['invoice_to_company_subject'] ?? ''));

        return $t !== '' ? $t : self::DEFAULT_INVOICE_SUBJECT;
    }

    private function effectiveInvoiceBody(): string
    {
        $t = trim((string) ($this->rawTemplates()['invoice_to_company_body'] ?? ''));

        return $t !== '' ? $t : self::DEFAULT_INVOICE_BODY;
    }

    private function effectiveMaintenanceSubject(): string
    {
        $t = trim((string) ($this->rawTemplates()['maintenance_subject'] ?? ''));

        return $t !== '' ? $t : self::DEFAULT_MAINTENANCE_SUBJECT;
    }

    private function effectiveMaintenanceBody(): string
    {
        $t = trim((string) ($this->rawTemplates()['maintenance_body'] ?? ''));

        return $t !== '' ? $t : self::DEFAULT_MAINTENANCE_BODY;
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function renderSubject(string $template, array $replacements): string
    {
        $out = $template;
        foreach ($replacements as $needle => $value) {
            $out = str_replace($needle, $this->plainForSubject($value), $out);
        }
        $out = trim(str_replace(["\r\n", "\n", "\r"], ' ', $out));

        return $out !== '' ? $out : '—';
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function renderBodyHtml(string $template, array $replacements): HtmlString
    {
        $out = $template;
        foreach ($replacements as $needle => $value) {
            $out = str_replace($needle, e($value), $out);
        }

        $out = $this->normalizeBodyNewlines($out);

        return new HtmlString(nl2br($out, false));
    }

    /**
     * Convierte saltos de línea reales y, por compatibilidad, la secuencia literal "\n" (error histórico
     * en textos por defecto guardados con comillas simples en PHP).
     */
    private function normalizeBodyNewlines(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if (str_contains($text, '\\n')) {
            $text = str_replace('\\n', "\n", $text);
        }

        return $text;
    }

    private function plainForSubject(string $value): string
    {
        $v = strip_tags($value);

        return trim(str_replace(["\r\n", "\n", "\r"], ' ', $v));
    }

    private function coalesceTrim(?string $stored, string $default): string
    {
        $s = $stored === null ? '' : trim($stored);

        return $s !== '' ? $s : $default;
    }

    private function sanitizeOneLine(string $value): string
    {
        $v = strip_tags($value);

        return trim(str_replace(["\r\n", "\n", "\r"], ' ', $v));
    }

    private function sanitizeMultiline(string $value): string
    {
        return trim(strip_tags($value));
    }
}
