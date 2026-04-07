<?php

namespace App\Support;

use App\Models\AppSetting;

/**
 * Margen entre importe declarado por el técnico y lo facturable a la empresa (configurable, p. ej. 10%).
 *
 * factura (empresa) = técnico ÷ ((100−p)/100). El catálogo puede tener `base_price` solo orientativo;
 * el importe real por línea es el que envía el empleado al registrar el servicio.
 */
final class CatalogPricing
{
    public static function globalTechnicianDiscountPercent(): float
    {
        return AppSetting::getFloat(AppSetting::KEY_TECHNICIAN_CATALOG_DISCOUNT_PERCENT, 10.0);
    }

    public static function effectiveTechnicianDiscountPercent(?float $catalogOverride): float
    {
        if ($catalogOverride !== null) {
            return max(0.0, min(99.99, (float) $catalogOverride));
        }

        return max(0.0, min(99.99, self::globalTechnicianDiscountPercent()));
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
