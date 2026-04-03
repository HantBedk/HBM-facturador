<?php

namespace App\Services;

use App\Models\Invoice;

class InvoiceCodeGenerator
{
    /**
     * FAC-{año}-{mes}-{consecutivo 3 dígitos} — consecutivo reinicia cada mes (ETAPA 4).
     * Ej.: FAC-2026-03-001, FAC-2026-03-002
     */
    public function nextForYearMonth(int $year, int $month): string
    {
        $prefix = sprintf('FAC-%d-%02d-', $year, $month);

        $lastCode = Invoice::query()
            ->where('code', 'like', $prefix.'%')
            ->orderByDesc('code')
            ->lockForUpdate()
            ->value('code');

        $next = 1;
        if ($lastCode !== null && preg_match('/-(\d{3})$/', $lastCode, $m)) {
            $next = (int) $m[1] + 1;
        }

        if ($next > 999) {
            throw new \RuntimeException('Consecutivo de factura agotado para el periodo.');
        }

        return $prefix.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }
}
