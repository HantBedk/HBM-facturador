<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PanelNotification extends Model
{
    public const TYPE_SERVICE_CREATED = 'service_created';

    public const TYPE_INVOICE_DRAFT = 'invoice_draft';

    public const TYPE_INVOICE_PENDING_APPROVAL = 'invoice_pending_approval';

    public const TYPE_INVOICE_PENDING_SEND = 'invoice_pending_send';

    public const TYPE_INVOICE_PARTIAL_PAYMENT = 'invoice_partial_payment';

    public const TYPE_CUTOFF_APPROACHING = 'cutoff_approaching';

    public const TYPE_ALERT_DRAFTS_PENDING = 'alert_drafts_pending';

    public const TYPE_ALERT_APPROVED_UNSENT = 'alert_approved_unsent';

    public const TYPE_ALERT_SERVICES_ZERO = 'alert_services_zero_amount';

    public const TYPE_CUTOFF_AUTO_SENT = 'cutoff_auto_sent';

    /** Técnico completó perfil (contacto / pago) por primera vez. */
    public const TYPE_EMPLEADO_PERFIL_COMPLETADO = 'empleado_perfil_completado';

    /** Técnico solicitó cambio de correo (pendiente de aprobación admin). */
    public const TYPE_EMAIL_CHANGE_REQUEST = 'email_change_request';

    /** Notificaciones mostradas solo al técnico (panel empleado). */
    public const TYPE_EMP_CORREO_APROBADO = 'emp_correo_aprobado';

    public const TYPE_EMP_CORREO_RECHAZADO = 'emp_correo_rechazado';

    /** Pago registrado en una factura donde el técnico tiene servicios. */
    public const TYPE_EMP_PAGO_FACTURA = 'emp_pago_factura';

    /** Admin borró datos de pago del técnico (p. ej. fallo al abonar). */
    public const TYPE_EMP_DATOS_PAGO_REQUIEREN_ACTUALIZACION = 'emp_datos_pago_actualizar';

    /** Técnico registró ítem «Otro» pendiente de alta en catálogo. */
    public const TYPE_CATALOG_SUGGESTION_PENDING = 'catalog_suggestion_pending';

    /** Admin modificó datos de un servicio del técnico (queda en estado corregido). */
    public const TYPE_EMP_SERVICIO_MODIFICADO_ADMIN = 'emp_servicio_modificado_admin';

    /** Admin marcó como eliminado un servicio del técnico. */
    public const TYPE_EMP_SERVICIO_ELIMINADO_ADMIN = 'emp_servicio_eliminado_admin';

    /** Factura aprobada que incluye servicios del técnico. */
    public const TYPE_EMP_SERVICIO_FACTURA_APROBADA = 'emp_servicio_factura_aprobada';

    /** Servicio del técnico quitado del borrador de una factura. */
    public const TYPE_EMP_SERVICIO_EXCLUIDO_BORRADOR = 'emp_servicio_excluido_borrador';

    protected $table = 'panel_notifications';

    /**
     * @return list<string>
     */
    public static function empleadoNotificationTypes(): array
    {
        return [
            self::TYPE_EMP_CORREO_APROBADO,
            self::TYPE_EMP_CORREO_RECHAZADO,
            self::TYPE_EMP_PAGO_FACTURA,
            self::TYPE_EMP_DATOS_PAGO_REQUIEREN_ACTUALIZACION,
            self::TYPE_EMP_SERVICIO_MODIFICADO_ADMIN,
            self::TYPE_EMP_SERVICIO_ELIMINADO_ADMIN,
            self::TYPE_EMP_SERVICIO_FACTURA_APROBADA,
            self::TYPE_EMP_SERVICIO_EXCLUIDO_BORRADOR,
        ];
    }

    public static function categoryForType(string $type): string
    {
        return match ($type) {
            self::TYPE_SERVICE_CREATED,
            self::TYPE_ALERT_SERVICES_ZERO,
            self::TYPE_CATALOG_SUGGESTION_PENDING,
            self::TYPE_EMP_SERVICIO_MODIFICADO_ADMIN,
            self::TYPE_EMP_SERVICIO_ELIMINADO_ADMIN,
            self::TYPE_EMP_SERVICIO_FACTURA_APROBADA,
            self::TYPE_EMP_SERVICIO_EXCLUIDO_BORRADOR => 'servicios',
            self::TYPE_EMPLEADO_PERFIL_COMPLETADO,
            self::TYPE_EMAIL_CHANGE_REQUEST => 'empleados',
            default => 'facturas',
        };
    }

    /**
     * @return list<string>
     */
    /**
     * Ruta del panel admin (Vue Router) al hacer clic en una notificación.
     * Usa `meta.link` si existe; si no, infiere por tipo e IDs en `meta`.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public static function resolveAdminPanelLink(string $type, ?array $meta): ?string
    {
        $meta = $meta ?? [];

        // Cambio de correo: la acción (aprobar/rechazar) está en Configuración → cuentas, no en el perfil.
        if ($type === self::TYPE_EMAIL_CHANGE_REQUEST) {
            if (isset($meta['empleado_id']) && is_numeric($meta['empleado_id'])) {
                return '/admin/configuracion/cuentas?usuario_id='.(int) $meta['empleado_id'];
            }

            return '/admin/configuracion/cuentas';
        }

        if ($type === self::TYPE_EMPLEADO_PERFIL_COMPLETADO
            && isset($meta['empleado_id']) && is_numeric($meta['empleado_id'])) {
            return '/admin/empleados/'.(int) $meta['empleado_id'].'/perfil';
        }

        if (isset($meta['link']) && is_string($meta['link']) && $meta['link'] !== '') {
            return $meta['link'];
        }

        $invoiceId = isset($meta['invoice_id']) && is_numeric($meta['invoice_id'])
            ? (int) $meta['invoice_id']
            : null;
        $serviceId = isset($meta['service_id']) && is_numeric($meta['service_id'])
            ? (int) $meta['service_id']
            : null;

        return match ($type) {
            self::TYPE_INVOICE_DRAFT,
            self::TYPE_INVOICE_PENDING_APPROVAL,
            self::TYPE_INVOICE_PENDING_SEND,
            self::TYPE_INVOICE_PARTIAL_PAYMENT => $invoiceId !== null
                ? '/admin/facturas/'.$invoiceId
                : '/admin/facturas',
            self::TYPE_SERVICE_CREATED => $serviceId !== null
                ? '/admin/servicios/'.$serviceId
                : '/admin/servicios',
            self::TYPE_ALERT_SERVICES_ZERO => '/admin/servicios',
            self::TYPE_CATALOG_SUGGESTION_PENDING => '/admin/catalogo-servicios',
            self::TYPE_EMPLEADO_PERFIL_COMPLETADO,
            self::TYPE_EMAIL_CHANGE_REQUEST => '/admin/configuracion/cuentas',
            self::TYPE_CUTOFF_APPROACHING,
            self::TYPE_ALERT_DRAFTS_PENDING,
            self::TYPE_ALERT_APPROVED_UNSENT,
            self::TYPE_CUTOFF_AUTO_SENT => '/admin/facturas',
            default => null,
        };
    }

    public static function typesInCategory(string $category): array
    {
        return match ($category) {
            'servicios' => [
                self::TYPE_SERVICE_CREATED,
                self::TYPE_ALERT_SERVICES_ZERO,
                self::TYPE_CATALOG_SUGGESTION_PENDING,
            ],
            'empleados' => [self::TYPE_EMPLEADO_PERFIL_COMPLETADO, self::TYPE_EMAIL_CHANGE_REQUEST],
            'facturas' => [
                self::TYPE_INVOICE_DRAFT,
                self::TYPE_INVOICE_PENDING_APPROVAL,
                self::TYPE_INVOICE_PENDING_SEND,
                self::TYPE_INVOICE_PARTIAL_PAYMENT,
                self::TYPE_CUTOFF_APPROACHING,
                self::TYPE_ALERT_DRAFTS_PENDING,
                self::TYPE_ALERT_APPROVED_UNSENT,
                self::TYPE_CUTOFF_AUTO_SENT,
            ],
            default => [],
        };
    }

    protected $fillable = [
        'user_id',
        'type',
        'message',
        'read',
        'meta',
        'dedupe_key',
    ];

    protected function casts(): array
    {
        return [
            'read' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
