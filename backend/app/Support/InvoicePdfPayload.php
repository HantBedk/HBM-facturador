<?php

namespace App\Support;

use App\Models\Invoice;

/**
 * Estructura de datos para PDF y respuestas JSON de factura (consulta pública y panel admin).
 */
class InvoicePdfPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function build(Invoice $invoice): array
    {
        $invoice->loadMissing(['company', 'services.user', 'payments' => fn ($q) => $q->orderBy('payment_date')]);

        $totalPaid = (string) $invoice->payments->sum('amount');
        $total = (string) $invoice->total;
        $balance = max(0, (float) $invoice->total - (float) $totalPaid);

        $services = $invoice->services->map(function ($s) {
            return [
                'service_date' => $s->service_date?->format('Y-m-d'),
                'code' => $s->code,
                'description' => $s->description,
                'service_type' => $s->service_type,
                'technician_name' => $s->user?->nombre,
                'amount' => (string) $s->amount,
            ];
        })->values()->all();

        $payments = $invoice->payments->map(function ($p) {
            return [
                'payment_date' => $p->payment_date?->format('Y-m-d'),
                'amount' => (string) $p->amount,
                'method' => $p->method,
                'notes' => $p->notes,
            ];
        })->values()->all();

        return [
            'invoice' => [
                'code' => $invoice->code,
                'number' => $invoice->code,
                'status' => $invoice->status,
                'status_label' => self::statusLabel($invoice->status),
                'period_month' => $invoice->period_month,
                'period_year' => $invoice->period_year,
                'period_label' => self::periodLabel((int) $invoice->period_month, (int) $invoice->period_year),
                'sent_at' => $invoice->sent_at?->toIso8601String(),
            ],
            'company' => [
                'nombre' => $invoice->company?->nombre,
                'nit' => $invoice->company?->nit,
            ],
            'services' => $services,
            'products' => [],
            'financial' => [
                'subtotal' => (string) $invoice->subtotal,
                'total' => $total,
                'total_paid' => $totalPaid,
                'balance' => number_format($balance, 2, '.', ''),
            ],
            'payments' => $payments,
        ];
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            Invoice::STATUS_BORRADOR => 'Borrador',
            Invoice::STATUS_APROBADA => 'Aprobada',
            Invoice::STATUS_ENVIADA => 'Enviada',
            Invoice::STATUS_PARCIALMENTE_PAGADA => 'Parcialmente pagada',
            Invoice::STATUS_PAGADA => 'Pagada',
            default => $status,
        };
    }

    private static function periodLabel(int $month, int $year): string
    {
        $months = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
        ];
        $name = $months[$month] ?? (string) $month;

        return $name.' '.$year;
    }
}
