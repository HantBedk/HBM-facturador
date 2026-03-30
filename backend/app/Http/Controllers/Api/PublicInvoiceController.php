<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use App\Support\InvoicePdfPayload;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class PublicInvoiceController extends Controller
{
    public function consult(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->merge([
            'code' => trim((string) $request->input('code', '')),
            'nit' => trim((string) $request->input('nit', '')),
        ]);

        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:64'],
            'nit' => ['nullable', 'string', 'max:32'],
        ]);

        $code = $validated['code'];
        $nit = $validated['nit'];

        if ($code === '' && $nit === '') {
            return response()->json([
                'message' => 'Indica el código de factura o el NIT.',
                'errors' => [
                    'code' => ['Completa al menos uno de los dos campos.'],
                    'nit' => ['Completa al menos uno de los dos campos.'],
                ],
            ], 422);
        }

        if ($code !== '') {
            $invoice = $this->findInvoiceByCode($code);
            $error = $this->evaluateSingleInvoiceAccess($invoice, $nit);
            if ($error !== null) {
                return $error;
            }

            return response()->json(array_merge(
                ['mode' => 'detail'],
                InvoicePdfPayload::build($invoice)
            ));
        }

        $normalizedNit = self::normalizeNit($nit);
        if ($normalizedNit === '') {
            return response()->json([
                'message' => 'El NIT indicado no es válido.',
            ], 422);
        }

        $companyIds = $this->companyIdsMatchingNit($nit);
        if ($companyIds === []) {
            return response()->json([
                'message' => 'No encontramos facturas registradas con ese NIT.',
            ], 404);
        }

        $invoices = Invoice::query()
            ->whereIn('company_id', $companyIds)
            ->whereIn('status', Invoice::PUBLIC_STATUSES)
            ->with(['company', 'services.user', 'payments' => fn ($q) => $q->orderBy('payment_date')])
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->orderByDesc('id')
            ->get();

        if ($invoices->isEmpty()) {
            return response()->json([
                'message' => 'No hay facturas disponibles para consulta con ese NIT.',
            ], 404);
        }

        $companies = $invoices->pluck('company')->filter()->unique('id')->map(fn (Company $co) => [
            'nombre' => $co->nombre,
            'nit' => $co->nit,
        ])->values();

        return response()->json([
            'mode' => 'history',
            'companies' => $companies,
            'invoices' => $invoices->map(fn (Invoice $inv) => InvoicePdfPayload::build($inv))->values()->all(),
        ]);
    }

    public function pdf(Request $request): \Symfony\Component\HttpFoundation\Response|\Illuminate\Http\JsonResponse
    {
        $request->merge([
            'code' => trim((string) $request->input('code', '')),
            'nit' => trim((string) $request->input('nit', '')),
        ]);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'nit' => ['nullable', 'string', 'max:32'],
        ]);

        $invoice = $this->findInvoiceByCode($validated['code']);
        $error = $this->evaluateSingleInvoiceAccess($invoice, $validated['nit']);
        if ($error !== null) {
            return $error;
        }

        try {
            $data = InvoicePdfPayload::build($invoice);
            $filename = 'factura-'.preg_replace('/[^a-zA-Z0-9_-]/', '_', $invoice->code).'.pdf';

            return Pdf::loadView('pdf.public_invoice', ['data' => $data])
                ->download($filename);
        } catch (\Throwable) {
            return response()->json([
                'message' => 'No pudimos generar el documento en este momento. Intenta más tarde.',
            ], 500);
        }
    }

    private function findInvoiceByCode(string $code): ?Invoice
    {
        if ($code === '') {
            return null;
        }

        return Invoice::query()
            ->whereRaw('LOWER(code) = ?', [mb_strtolower($code)])
            ->with(['company', 'services.user', 'payments' => fn ($q) => $q->orderBy('payment_date')])
            ->first();
    }

    /**
     * Acceso por código: factura en estados públicos. Si el cliente envía NIT y la empresa tiene NIT,
     * deben coincidir (refuerzo). Sin NIT en el formulario no se exige (clientes sin NIT / enlace por código).
     */
    private function evaluateSingleInvoiceAccess(?Invoice $invoice, string $nitInput): ?\Illuminate\Http\JsonResponse
    {
        if ($invoice === null) {
            return response()->json([
                'message' => 'No encontramos una factura con los datos indicados.',
            ], 404);
        }

        if ($invoice->status === Invoice::STATUS_BORRADOR) {
            return response()->json([
                'message' => 'La factura aún no está disponible para consulta.',
            ], 403);
        }

        if (! in_array($invoice->status, Invoice::PUBLIC_STATUSES, true)) {
            return response()->json([
                'message' => 'La factura aún no está disponible para consulta.',
            ], 403);
        }

        $nitTrim = trim($nitInput);
        $companyNit = $invoice->company?->nit;
        $companyNitTrim = $companyNit !== null ? trim((string) $companyNit) : '';

        if ($nitTrim !== '' && $companyNitTrim !== '') {
            if (! hash_equals(self::normalizeNit($companyNitTrim), self::normalizeNit($nitTrim))) {
                return response()->json([
                    'message' => 'No encontramos una factura con los datos indicados.',
                ], 404);
            }
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private function companyIdsMatchingNit(string $nitInput): array
    {
        $target = self::normalizeNit($nitInput);
        if ($target === '') {
            return [];
        }

        return Company::query()
            ->whereNotNull('nit')
            ->where('nit', '!=', '')
            ->get()
            ->filter(fn (Company $c) => hash_equals($target, self::normalizeNit((string) $c->nit)))
            ->pluck('id')
            ->all();
    }

    /**
     * Normaliza NIT para comparación: minúsculas y solo alfanuméricos.
     */
    public static function normalizeNit(string $nit): string
    {
        $s = mb_strtolower(trim($nit));

        return (string) preg_replace('/[^a-z0-9]/u', '', $s);
    }

}
