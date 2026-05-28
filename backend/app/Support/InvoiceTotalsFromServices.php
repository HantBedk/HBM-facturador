<?php

namespace App\Support;

use App\Models\Service;

/**
 * Importes de factura a partir de servicios: subtotal = suma de importes de línea (base sin IVA);
 * total = subtotal + IVA, donde el IVA por línea es amount × (iva_percent del catálogo / 100).
 */
final class InvoiceTotalsFromServices
{
    /**
     * @param  list<int>  $serviceIds
     * @return array{subtotal: string, total: string}
     */
    public static function fromServiceIds(array $serviceIds): array
    {
        if ($serviceIds === []) {
            return ['subtotal' => '0.00', 'total' => '0.00'];
        }

        $services = Service::query()
            ->whereIn('id', $serviceIds)
            ->with('catalog:id,iva_percent')
            ->get();

        $subtotal = 0.0;
        $iva = 0.0;

        foreach ($services as $s) {
            $amt = (float) $s->amount;
            $subtotal += $amt;
            $pct = (float) ($s->catalog?->iva_percent ?? 0);
            if ($pct > 0) {
                $iva += round($amt * $pct / 100.0, 2);
            }
        }

        $subtotal = round($subtotal, 2);
        $total = round($subtotal + $iva, 2);

        return [
            'subtotal' => number_format($subtotal, 2, '.', ''),
            'total' => number_format($total, 2, '.', ''),
        ];
    }
}
