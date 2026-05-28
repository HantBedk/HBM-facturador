<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Invoice;
use Carbon\Carbon;

class InvoiceCodeGenerator
{
    /**
     * Formato FAC-YYMMDD-SIGLA; si ya hay factura ese día para la empresa, FAC-YYMMDD-SIGLA-2, -3, …
     * (fecha de creación del borrador, zona app).
     */
    public function nextForCompanyOnDate(Company $company, Carbon $at): string
    {
        $sigla = strtoupper((string) $company->factura_sigla);
        if (strlen($sigla) !== 3 || ! ctype_alpha($sigla)) {
            throw new \InvalidArgumentException('La empresa debe tener una sigla de facturación de 3 letras (A-Z).');
        }

        $tz = config('app.timezone');
        $d = $at->copy()->timezone($tz);
        $base = sprintf(
            'FAC-%02d%02d%02d-%s',
            $d->year % 100,
            (int) $d->format('n'),
            (int) $d->format('j'),
            $sigla
        );

        if (! Invoice::query()->where('code', $base)->lockForUpdate()->exists()) {
            return $base;
        }

        for ($seq = 2; $seq <= 999; $seq++) {
            $code = $base.'-'.$seq;
            if (! Invoice::query()->where('code', $code)->lockForUpdate()->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException(
            'No se pudo asignar un código de factura único para esta empresa el '.$d->format('d/m/Y').'.'
        );
    }
}
