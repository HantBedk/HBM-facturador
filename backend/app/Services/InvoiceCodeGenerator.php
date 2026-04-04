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
}
