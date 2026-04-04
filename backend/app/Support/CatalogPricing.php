<?php

namespace App\Support;

use App\Models\AppSetting;

/**
 * Precio de lista/factura vs importe que maneja el técnico (X% menos que la factura).
 *
 * Si el técnico ve (100−p)% del precio de factura, entonces: factura = técnico ÷ ((100−p)/100).
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
