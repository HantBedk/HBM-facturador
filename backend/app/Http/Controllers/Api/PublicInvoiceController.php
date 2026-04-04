<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use App\Support\InvoicePdfPayload;
use App\Support\SafeUtf8;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicInvoiceController extends Controller
{
    /**
     * Consulta pública: `query` es el código de factura (detalle completo) o el NIT de la empresa (listado de facturas públicas).
     */
    public function consult(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'max:96'],
        ]);

        $query = trim($validated['query']);
        if ($query === '') {
            return response()->json([
                'message' => 'Indique el código de factura o el NIT de la empresa.',
            ], 422);
        }

        $invoice = $this->findInvoiceByCode(self::normalizeInvoiceCodeForLookup($query));
        if ($invoice === null) {
            $invoice = $this->findInvoiceByCode($query);
        }
        if ($invoice !== null) {
            $error = $this->assertInvoicePubliclyViewable($invoice);
            if ($error !== null) {
                return $error;
            }

            return response()->json(array_merge(
                ['kind' => 'invoice'],
                InvoicePdfPayload::build($invoice)
            ));
        }

        $company = $this->findCompanyByNormalizedNit($query);
        if ($company !== null) {
            $rows = Invoice::query()
                ->where('company_id', $company->id)
                ->whereIn('status', Invoice::PUBLIC_STATUSES)
                ->withSum('payments', 'amount')
                ->orderByDesc('period_year')
                ->orderByDesc('period_month')
                ->orderByDesc('id')
                ->get();

            return response()->json([
                'kind' => 'invoice_list',
                'company' => [
                    'nombre' => $company->nombre,
                    'nit' => $company->nit,
                ],
                'invoices' => $rows->map(fn (Invoice $inv) => $this->formatPublicInvoiceListRow($inv))->values()->all(),
            ]);
        }

        return $this->denyPublicAccess();
    }

    public function pdf(Request $request): Response|JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        $rawCode = trim($validated['code']);
        $invoice = $this->findInvoiceByCode(self::normalizeInvoiceCodeForLookup($rawCode));
        if ($invoice === null) {
            $invoice = $this->findInvoiceByCode($rawCode);
        }
        $error = $this->assertInvoicePubliclyViewable($invoice);
        if ($error !== null) {
            return $error;
        }

        try {
            $data = InvoicePdfPayload::build($invoice);
            $filename = 'factura-'.preg_replace('/[^a-zA-Z0-9_-]/', '_', $invoice->code).'.pdf';

            return Pdf::loadView('pdf.public_invoice', ['data' => $data])
                ->download($filename);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug')
                    ? ('PDF: '.$e->getMessage())
                    : 'No pudimos generar el documento en este momento. Intenta más tarde.',
            ], 500);
        }
    }

    private function findInvoiceByCode(string $code): ?Invoice
    {
        if ($code === '') {
            return null;
        }

        return Invoice::query()
            ->whereRaw('LOWER(code) = ?', [SafeUtf8::lower($code)])
            ->with(['company', 'services.user', 'payments' => fn ($q) => $q->orderBy('payment_date')])
            ->first();
    }

    private function findCompanyByNormalizedNit(string $raw): ?Company
    {
        $target = self::normalizeNit($raw);
        if ($target === '') {
            return null;
        }

        return Company::query()
            ->whereNotNull('nit')
            ->where('nit', '!=', '')
            ->get()
            ->first(fn (Company $c) => hash_equals(self::normalizeNit((string) $c->nit), $target));
    }

    private function formatPublicInvoiceListRow(Invoice $invoice): array
    {
        $paid = (float) ($invoice->payments_sum_amount ?? 0);
        $total = (float) $invoice->total;
        $balance = max(0, $total - $paid);

        return [
            'id' => $invoice->id,
            'code' => $invoice->code,
            'period_label' => InvoicePdfPayload::invoicePeriodLabel((int) $invoice->period_month, (int) $invoice->period_year),
            'status' => $invoice->status,
            'status_label' => InvoicePdfPayload::invoiceStatusLabel($invoice->status),
            'total' => number_format($total, 2, '.', ''),
            'total_paid' => number_format($paid, 2, '.', ''),
            'balance' => number_format($balance, 2, '.', ''),
        ];
    }

    private function assertInvoicePubliclyViewable(?Invoice $invoice): ?JsonResponse
    {
        if ($invoice === null) {
            return $this->denyPublicAccess();
        }

        if ($invoice->status === Invoice::STATUS_BORRADOR) {
            return response()->json([
                'message' => 'La factura existe pero sigue en borrador. Cuando un administrador la apruebe o la marque como enviada, podrá consultarla y descargar el PDF desde aquí.',
                'code' => 'invoice_unavailable',
                'reason' => 'borrador',
            ], 403);
        }

        if (! in_array($invoice->status, Invoice::PUBLIC_STATUSES, true)) {
            return response()->json([
                'message' => 'La factura existe pero no está disponible para consulta pública en su estado actual.',
                'code' => 'invoice_unavailable',
                'reason' => 'estado_no_publico',
            ], 403);
        }

        return null;
    }

    /**
     * Misma respuesta ante factura inexistente, NIT desconocido o datos no coincidentes (reduce enumeración).
     */
    private function denyPublicAccess(): JsonResponse
    {
        return response()->json([
            'message' => 'No encontramos una factura ni una empresa registrada con los datos indicados.',
            'code' => 'public_invoice_denied',
        ], 404);
    }

    /**
     * Normaliza NIT para comparación: minúsculas y solo alfanuméricos.
     */
    public static function normalizeNit(string $nit): string
    {
        $s = mb_strtolower(trim($nit));

        return (string) preg_replace('/[^a-z0-9]/u', '', $s);
    }

    /**
     * Quita espacios y unifica guiones tipográficos para coincidir con el código almacenado (FAC-…).
     */
    public static function normalizeInvoiceCodeForLookup(string $raw): string
    {
        $s = trim($raw);
        if ($s === '') {
            return '';
        }
        $s = preg_replace('/\s+/u', '', $s) ?? $s;
        foreach (["\u{2013}", "\u{2014}", "\u{2212}", '−'] as $dash) {
            $s = str_replace($dash, '-', $s);
        }

        return $s;
    }
}
