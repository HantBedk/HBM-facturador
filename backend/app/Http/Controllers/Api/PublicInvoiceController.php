<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\InvoicePublicAccessService;
use App\Support\InvoicePdfPayload;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicInvoiceController extends Controller
{
    public function consult(Request $request, InvoicePublicAccessService $publicAccess): JsonResponse
    {
        $request->merge([
            'code' => trim((string) $request->input('code', '')),
            'verification_code' => trim((string) $request->input('verification_code', '')),
            'nit' => trim((string) $request->input('nit', '')),
        ]);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'verification_code' => ['required', 'string', 'max:128'],
            'nit' => ['nullable', 'string', 'max:32'],
        ]);

        $invoice = $this->findInvoiceByCode($validated['code']);
        $error = $this->evaluateSingleInvoiceAccess(
            $invoice,
            $validated['verification_code'],
            $validated['nit'] ?? '',
            $publicAccess
        );
        if ($error !== null) {
            return $error;
        }

        return response()->json(InvoicePdfPayload::build($invoice));
    }

    public function pdf(Request $request, InvoicePublicAccessService $publicAccess): Response|JsonResponse
    {
        $request->merge([
            'code' => trim((string) $request->input('code', '')),
            'verification_code' => trim((string) $request->input('verification_code', '')),
            'nit' => trim((string) $request->input('nit', '')),
        ]);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'verification_code' => ['required', 'string', 'max:128'],
            'nit' => ['nullable', 'string', 'max:32'],
        ]);

        $invoice = $this->findInvoiceByCode($validated['code']);
        $error = $this->evaluateSingleInvoiceAccess(
            $invoice,
            $validated['verification_code'],
            $validated['nit'] ?? '',
            $publicAccess
        );
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
     * Factura en estados públicos + código de verificación (hash) + NIT opcional como refuerzo.
     */
    private function evaluateSingleInvoiceAccess(
        ?Invoice $invoice,
        string $verificationPlain,
        string $nitInput,
        InvoicePublicAccessService $publicAccess
    ): ?JsonResponse {
        if ($invoice === null) {
            return $this->denyPublicAccess();
        }

        if ($invoice->status === Invoice::STATUS_BORRADOR) {
            return response()->json([
                'message' => 'La factura aún no está disponible para consulta.',
                'code' => 'invoice_unavailable',
            ], 403);
        }

        if (! in_array($invoice->status, Invoice::PUBLIC_STATUSES, true)) {
            return response()->json([
                'message' => 'La factura aún no está disponible para consulta.',
                'code' => 'invoice_unavailable',
            ], 403);
        }

        if (! $publicAccess->verifyPlain($invoice, $verificationPlain)) {
            return $this->denyPublicAccess();
        }

        $nitTrim = trim($nitInput);
        $companyNit = $invoice->company?->nit;
        $companyNitTrim = $companyNit !== null ? trim((string) $companyNit) : '';

        if ($nitTrim !== '' && $companyNitTrim !== '') {
            if (! hash_equals(self::normalizeNit($companyNitTrim), self::normalizeNit($nitTrim))) {
                return $this->denyPublicAccess();
            }
        }

        return null;
    }

    /**
     * Misma respuesta ante factura inexistente o credenciales incorrectas (reduce enumeración).
     */
    private function denyPublicAccess(): JsonResponse
    {
        return response()->json([
            'message' => 'No encontramos una factura con los datos indicados o el código de verificación no es válido.',
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
}
