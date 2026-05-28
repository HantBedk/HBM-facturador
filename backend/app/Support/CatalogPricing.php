<?php

namespace App\Support;

use App\Models\AppSetting;

/**
 * Margen entre importe de referencia (técnico / operación) y lo facturable a la empresa (% configurable).
 *
 * factura (empresa) = referencia ÷ ((100−p)/100). Ítems de catálogo pueden tener override por fila;
 * inventario comercial (líneas «Venta equipo:» / «Alquiler equipo:») aplica márgenes globales de venta y alquiler sobre la referencia interna.
 * Los pisos mínimos de cada margen se guardan en app_settings y los define el admin.
 */
final class CatalogPricing
{
    /** Si aún no existe fila en BD, usar estos pisos por defecto. */
    private const FALLBACK_FLOOR_SERVICE = 5.0;

    private const FALLBACK_FLOOR_INVENTORY_SALE = 10.0;

    private const FALLBACK_FLOOR_INVENTORY_RENTAL = 8.0;

    public const MAX_GLOBAL_DISCOUNT_PERCENT = 95.0;

    public static function floorServiceDiscountPercent(): float
    {
        return AppSetting::getFloat(
            AppSetting::KEY_TECHNICIAN_CATALOG_DISCOUNT_FLOOR_PERCENT,
            self::FALLBACK_FLOOR_SERVICE
        );
    }

    public static function floorInventorySaleDiscountPercent(): float
    {
        return AppSetting::getFloat(
            AppSetting::KEY_TECHNICIAN_INVENTORY_SALE_DISCOUNT_FLOOR_PERCENT,
            self::FALLBACK_FLOOR_INVENTORY_SALE
        );
    }

    public static function floorInventoryRentalDiscountPercent(): float
    {
        return AppSetting::getFloat(
            AppSetting::KEY_TECHNICIAN_INVENTORY_RENTAL_DISCOUNT_FLOOR_PERCENT,
            self::FALLBACK_FLOOR_INVENTORY_RENTAL
        );
    }

    /** Margen de servicio: catálogo, líneas «Otro» y fallback de ítem sin override. */
    public static function globalServiceDiscountPercent(): float
    {
        $v = AppSetting::getFloat(AppSetting::KEY_TECHNICIAN_CATALOG_DISCOUNT_PERCENT, 10.0);
        $floor = self::floorServiceDiscountPercent();

        return max($floor, min(self::MAX_GLOBAL_DISCOUNT_PERCENT, $v));
    }

    public static function globalInventorySaleDiscountPercent(): float
    {
        $v = AppSetting::getFloat(AppSetting::KEY_TECHNICIAN_INVENTORY_SALE_DISCOUNT_PERCENT, 10.0);
        $floor = self::floorInventorySaleDiscountPercent();

        return max($floor, min(self::MAX_GLOBAL_DISCOUNT_PERCENT, $v));
    }

    public static function globalInventoryRentalDiscountPercent(): float
    {
        $v = AppSetting::getFloat(AppSetting::KEY_TECHNICIAN_INVENTORY_RENTAL_DISCOUNT_PERCENT, 10.0);
        $floor = self::floorInventoryRentalDiscountPercent();

        return max($floor, min(self::MAX_GLOBAL_DISCOUNT_PERCENT, $v));
    }

    /**
     * Límites por campo para el panel admin y validación API (mismas claves que el payload de margen).
     *
     * @return array<string, array{min: float, max: float}>
     */
    public static function globalMarginFieldConstraints(): array
    {
        $max = self::MAX_GLOBAL_DISCOUNT_PERCENT;

        return [
            'technician_service_discount_percent' => [
                'min' => self::floorServiceDiscountPercent(),
                'max' => $max,
            ],
            'technician_inventory_sale_discount_percent' => [
                'min' => self::floorInventorySaleDiscountPercent(),
                'max' => $max,
            ],
            'technician_inventory_rental_discount_percent' => [
                'min' => self::floorInventoryRentalDiscountPercent(),
                'max' => $max,
            ],
        ];
    }

    /** @deprecated usar globalServiceDiscountPercent() */
    public static function globalTechnicianDiscountPercent(): float
    {
        return self::globalServiceDiscountPercent();
    }

    /**
     * Línea custom: «Venta equipo:» / «Alquiler equipo:» / resto (servicio).
     */
    public static function technicianDiscountPercentForCustomLine(string $customNameOrLabel): float
    {
        $n = trim($customNameOrLabel);
        if (str_starts_with($n, 'Venta equipo:')) {
            return max(0.0, min(99.99, self::globalInventorySaleDiscountPercent()));
        }
        if (str_starts_with($n, 'Alquiler equipo:')) {
            return max(0.0, min(99.99, self::globalInventoryRentalDiscountPercent()));
        }

        return max(0.0, min(99.99, self::globalServiceDiscountPercent()));
    }

    public static function effectiveTechnicianDiscountPercent(?float $catalogOverride): float
    {
        if ($catalogOverride !== null) {
            return max(0.0, min(99.99, (float) $catalogOverride));
        }

        return max(0.0, min(99.99, self::globalServiceDiscountPercent()));
    }

    /**
     * Importe que ve el técnico a partir del precio de lista (factura).
     */
    public static function technicianAmountFromListPrice(float $listPrice, float $discountPercent): string
    {
        if ($discountPercent <= 0 || $discountPercent >= 100) {
            return number_format($listPrice, 2, '.', '');
        }
        $f = (100 - $discountPercent) / 100;

        return number_format(round($listPrice * $f, 2), 2, '.', '');
    }

    /**
     * Importe que va a la factura a partir de lo que ingresa el técnico (línea «Otro»).
     */
    public static function billedAmountFromTechnicianEntry(float $technicianAmount, float $discountPercent): string
    {
        if ($discountPercent <= 0 || $discountPercent >= 100) {
            return number_format($technicianAmount, 2, '.', '');
        }
        $f = (100 - $discountPercent) / 100;

        return number_format(round($technicianAmount / $f, 2), 2, '.', '');
    }
}
