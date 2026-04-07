<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Invoice;
use Carbon\Carbon;

class InvoiceCodeGenerator
{
    /**
     * Formato FAC-YYMMDD-SIGLA. Máximo una factura por empresa y día (fecha de creación del borrador, zona app).
     */
    public function nextForCompanyOnDate(Company $company, Carbon $at): string
    {
        $sigla = strtoupper((string) $company->factura_sigla);
        if (strlen($sigla) !== 3 || ! ctype_alpha($sigla)) {
            throw new \InvalidArgumentException('La empresa debe tener una sigla de facturación de 3 letras (A-Z).');
        }

        $tz = config('app.timezone');
        $d = $at->copy()->timezone($tz);
        $code = sprintf(
            'FAC-%02d%02d%02d-%s',
            $d->year % 100,
            (int) $d->format('n'),
            (int) $d->format('j'),
            $sigla
        );

        $exists = Invoice::query()
            ->where('code', $code)
            ->lockForUpdate()
            ->exists();

        if ($exists) {
            throw new \RuntimeException(
                'Ya existe una factura para esta empresa el '.$d->format('d/m/Y').' (máximo 1 por día). Código: '.$code.'.'
            );
        }

        return $code;
    }

    /**
     * Venta sin empresa en base de datos: FAC-YYMMDD-W01, W02… (único por día).
     */
    public function nextForCounterSale(Carbon $at): string
    {
        $tz = config('app.timezone');
        $d = $at->copy()->timezone($tz);
        $prefix = sprintf(
            'FAC-%02d%02d%02d-W',
            $d->year % 100,
            (int) $d->format('n'),
            (int) $d->format('j')
        );

        $max = 0;
        $rows = Invoice::query()
            ->where('code', 'like', $prefix.'%')
            ->lockForUpdate()
            ->pluck('code');
        foreach ($rows as $c) {
            if (preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/', (string) $c, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        $next = $max + 1;
        if ($next > 99) {
            throw new \RuntimeException('Límite diario de facturas de mostrador alcanzado (99).');
        }

        return $prefix.str_pad((string) $next, 2, '0', STR_PAD_LEFT);
    }
}
