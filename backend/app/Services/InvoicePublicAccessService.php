<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class InvoicePublicAccessService
{
    /**
     * Genera token de consulta pública si aún no existe. Devuelve el texto plano solo la primera vez.
     */
    public function ensureToken(Invoice $invoice): ?string
    {
        if ($invoice->public_access_token !== null && $invoice->public_access_token !== '') {
            return null;
        }

        $plain = Str::random(40);
        $invoice->public_access_token = Hash::make($plain);
        $invoice->save();

        return $plain;
    }

    public function regenerate(Invoice $invoice): string
    {
        $plain = Str::random(40);
        $invoice->public_access_token = Hash::make($plain);
        $invoice->save();

        return $plain;
    }

    public function verifyPlain(Invoice $invoice, string $plain): bool
    {
        if ($invoice->public_access_token === null || $invoice->public_access_token === '') {
            return false;
        }

        return Hash::check(trim($plain), $invoice->public_access_token);
    }

    /** Para seed/tests con valor conocido. */
    public function setPlainToken(Invoice $invoice, string $plain): void
    {
        $invoice->public_access_token = Hash::make($plain);
        $invoice->save();
    }
}
