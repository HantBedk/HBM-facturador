<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    /** Margen global servicio / catálogo / línea «Otro» (no inventario comercial). */
    public const KEY_TECHNICIAN_CATALOG_DISCOUNT_PERCENT = 'technician_catalog_discount_percent';

    /** Margen venta de inventario (líneas «Venta equipo:»). */
    public const KEY_TECHNICIAN_INVENTORY_SALE_DISCOUNT_PERCENT = 'technician_inventory_sale_discount_percent';

    /** Margen alquiler de inventario (líneas «Alquiler equipo:»). */
    public const KEY_TECHNICIAN_INVENTORY_RENTAL_DISCOUNT_PERCENT = 'technician_inventory_rental_discount_percent';

    /** Piso mínimo del margen servicio (no se puede guardar ni facturar por debajo). */
    public const KEY_TECHNICIAN_CATALOG_DISCOUNT_FLOOR_PERCENT = 'technician_catalog_discount_floor_percent';

    /** Piso mínimo del margen venta inventario. */
    public const KEY_TECHNICIAN_INVENTORY_SALE_DISCOUNT_FLOOR_PERCENT = 'technician_inventory_sale_discount_floor_percent';

    /** Piso mínimo del margen alquiler inventario. */
    public const KEY_TECHNICIAN_INVENTORY_RENTAL_DISCOUNT_FLOOR_PERCENT = 'technician_inventory_rental_discount_floor_percent';

    public const KEY_AUTOMATION_DRAFT_GENERATION_ENABLED = 'automation_draft_generation_enabled';

    public const KEY_AUTOMATION_DRAFT_GENERATION_DAY = 'automation_draft_generation_day';

    public const KEY_AUTOMATION_DRAFT_PERIOD = 'automation_draft_period';

    public const KEY_INVENTORY_LOCATIONS = 'inventory_locations';

    /** @var string Tipos de activo permitidos en altas (JSON array de strings). */
    public const KEY_INVENTORY_ASSET_TYPES = 'inventory_asset_types';

    /** @var string Estados físicos permitidos (JSON array de strings). */
    public const KEY_INVENTORY_PHYSICAL_CONDITIONS = 'inventory_physical_conditions';

    /** Lista explícita de IDs de usuario titulares de inventario interno (JSON array de enteros). */
    public const KEY_INVENTORY_HOLDERS = 'inventory_holder_user_ids';

    /** Técnicos pueden registrar venta de inventario como servicio (boolean en JSON). */
    public const KEY_EMPLEADO_INVENTORY_VENTA_ENABLED = 'empleado_inventory_venta_enabled';

    /** Técnicos pueden registrar alquiler de inventario como servicio (boolean en JSON). */
    public const KEY_EMPLEADO_INVENTORY_ALQUILER_ENABLED = 'empleado_inventory_alquiler_enabled';

    /**
     * Remitente para correos transaccionales (OTP inventario, facturas, recuperación clave, etc.).
     * Valor JSON: { "address": "noreply@dominio.com", "name": "Nombre visible" }.
     * Si `address` está vacío o no es un email válido, se usa MAIL_FROM_* del .env.
     */
    public const KEY_MAIL_NOTIFICATIONS_FROM = 'mail_notifications_from';

    protected $fillable = [
        'key',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }

    public static function getFloat(string $key, float $default = 0.0): float
    {
        $row = static::query()->where('key', $key)->first();
        if ($row === null || $row->value === null || $row->value === '') {
            return $default;
        }

        return (float) $row->value;
    }

    public static function getInt(string $key, int $default): int
    {
        $row = static::query()->where('key', $key)->first();
        if ($row === null || $row->value === null || $row->value === '') {
            return $default;
        }

        return (int) $row->value;
    }

    public static function getBool(string $key, bool $default): bool
    {
        $row = static::query()->where('key', $key)->first();
        if ($row === null || $row->value === null) {
            return $default;
        }

        $v = $row->value;
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return ((int) $v) === 1;
        }
        if (is_string($v)) {
            $s = strtolower(trim($v));

            return in_array($s, ['1', 'true', 'yes', 'on'], true);
        }

        return $default;
    }

    public static function getString(string $key, string $default): string
    {
        $row = static::query()->where('key', $key)->first();
        if ($row === null || $row->value === null || $row->value === '') {
            return $default;
        }

        return (string) $row->value;
    }

    public static function setValue(string $key, string $value): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }

    /**
     * @param  mixed  $value  Escalar o estructura serializable a JSON (columna json).
     */
    public static function setJsonValue(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }
}
