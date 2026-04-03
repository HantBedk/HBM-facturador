<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\PanelNotification;

/**
 * Preferencias globales: qué tipos de aviso pueden recibir los técnicos (panel empleado).
 */
class EmpleadoNotificacionSettings
{
    public const SETTING_KEY = 'empleado_notificaciones';

    /**
     * @return array<string, bool>
     */
    public function defaultMap(): array
    {
        return array_fill_keys(PanelNotification::empleadoNotificationTypes(), true);
    }

    /**
     * @return array<string, bool>
     */
    public function getMap(): array
    {
        $defaults = $this->defaultMap();
        $row = AppSetting::query()->where('key', self::SETTING_KEY)->first();
        if ($row === null || ! is_array($row->value)) {
            return $defaults;
        }

        /** @var array<string, mixed> $stored */
        $stored = $row->value;
        foreach ($defaults as $type => $def) {
            if (array_key_exists($type, $stored)) {
                $defaults[$type] = (bool) $stored[$type];
            }
        }

        return $defaults;
    }

    public function isEnabled(string $type): bool
    {
        if (! in_array($type, PanelNotification::empleadoNotificationTypes(), true)) {
            return true;
        }

        $map = $this->getMap();

        return ($map[$type] ?? true) === true;
    }

    /**
     * @param  array<string, bool>  $types Solo claves conocidas se persisten.
     */
    public function saveMap(array $types): void
    {
        $merged = $this->defaultMap();
        foreach ($merged as $type => $def) {
            if (array_key_exists($type, $types)) {
                $merged[$type] = (bool) $types[$type];
            }
        }

        AppSetting::query()->updateOrCreate(
            ['key' => self::SETTING_KEY],
            ['value' => $merged],
        );
    }

    /**
     * Para API admin: tipos con etiqueta y estado.
     *
     * @return list<array{type: string, label: string, enabled: bool}>
     */
    public function getMapWithLabels(): array
    {
        $map = $this->getMap();
        $labels = self::labels();

        $out = [];
        foreach (PanelNotification::empleadoNotificationTypes() as $type) {
            $out[] = [
                'type' => $type,
                'label' => $labels[$type] ?? $type,
                'enabled' => $map[$type] ?? true,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            PanelNotification::TYPE_EMP_CORREO_APROBADO => 'Correo: solicitud aprobada',
            PanelNotification::TYPE_EMP_CORREO_RECHAZADO => 'Correo: solicitud rechazada',
            PanelNotification::TYPE_EMP_PAGO_FACTURA => 'Pagos registrados en facturas (donde participa el técnico)',
            PanelNotification::TYPE_EMP_DATOS_PAGO_REQUIEREN_ACTUALIZACION => 'Aviso al borrar datos de pago desde administración',
        ];
    }
}
