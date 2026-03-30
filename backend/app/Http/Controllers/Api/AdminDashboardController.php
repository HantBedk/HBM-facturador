<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class AdminDashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $now = Carbon::now()->timezone(config('app.timezone'));
        $year = (int) $now->year;
        $month = (int) $now->month;

        $metrics = $this->buildMetrics($year, $month);
        $invoiceStatusCounts = $this->invoiceStatusCounts();
        $recent = [
            'services' => $this->recentServices(),
            'invoices' => $this->recentInvoices(),
            'payments' => $this->recentPayments(),
        ];

        return response()->json([
            'period' => [
                'year' => $year,
                'month' => $month,
                'label' => $this->periodLabel($month, $year),
            ],
            'metrics' => $metrics,
            'invoice_status_counts' => $invoiceStatusCounts,
            'recent' => $recent,
            'generated_at' => $now->toIso8601String(),
        ]);
    }

    /**
     * @return array{invoiced_month: string, received_month: string, pending_collect: string, invoices_count_month: int}
     */
    private function buildMetrics(int $year, int $month): array
    {
        $invoicedMonth = (string) Invoice::query()
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->where('status', '!=', Invoice::STATUS_BORRADOR)
            ->sum('total');

        $receivedMonth = (string) Payment::query()
            ->whereYear('payment_date', $year)
            ->whereMonth('payment_date', $month)
            ->sum('amount');

        $pendingCollect = Invoice::query()
            ->where('status', '!=', Invoice::STATUS_BORRADOR)
            ->withSum('payments', 'amount')
            ->get()
            ->sum(function (Invoice $invoice) {
                $paid = (float) ($invoice->payments_sum_amount ?? 0);

                return max(0, (float) $invoice->total - $paid);
            });

        $invoicesCountMonth = Invoice::query()
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->where('status', '!=', Invoice::STATUS_BORRADOR)
            ->count();

        return [
            'invoiced_month' => $invoicedMonth,
            'received_month' => $receivedMonth,
            'pending_collect' => number_format($pendingCollect, 2, '.', ''),
            'invoices_count_month' => $invoicesCountMonth,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function invoiceStatusCounts(): array
    {
        $statuses = [
            Invoice::STATUS_BORRADOR,
            Invoice::STATUS_APROBADA,
            Invoice::STATUS_ENVIADA,
            Invoice::STATUS_PARCIALMENTE_PAGADA,
            Invoice::STATUS_PAGADA,
        ];

        $raw = Invoice::query()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        $out = [];
        foreach ($statuses as $st) {
            $out[$st] = (int) ($raw[$st] ?? 0);
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentServices(int $limit = 8): array
    {
        return Service::query()
            ->with(['company:id,nombre', 'user:id,nombre'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(function (Service $s) {
                return [
                    'id' => $s->id,
                    'code' => $s->code,
                    'description' => Str::limit((string) $s->description, 100),
                    'amount' => (string) $s->amount,
                    'status' => $s->status,
                    'company_name' => $s->company?->nombre,
                    'user_name' => $s->user?->nombre,
                    'service_date' => $s->service_date?->format('Y-m-d'),
                    'created_at' => $s->created_at?->toIso8601String(),
                ];
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentInvoices(int $limit = 8): array
    {
        return Invoice::query()
            ->with(['company:id,nombre'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(function (Invoice $inv) {
                return [
                    'id' => $inv->id,
                    'code' => $inv->code,
                    'status' => $inv->status,
                    'status_label' => $this->statusLabel($inv->status),
                    'total' => (string) $inv->total,
                    'company_name' => $inv->company?->nombre,
                    'period_label' => $this->periodLabel((int) $inv->period_month, (int) $inv->period_year),
                    'created_at' => $inv->created_at?->toIso8601String(),
                ];
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentPayments(int $limit = 8): array
    {
        return Payment::query()
            ->with(['invoice:id,code,company_id', 'invoice.company:id,nombre'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(function (Payment $p) {
                return [
                    'id' => $p->id,
                    'invoice_id' => $p->invoice_id,
                    'amount' => (string) $p->amount,
                    'method' => $p->method,
                    'payment_date' => $p->payment_date?->format('Y-m-d'),
                    'invoice_code' => $p->invoice?->code,
                    'company_name' => $p->invoice?->company?->nombre,
                    'created_at' => $p->created_at?->toIso8601String(),
                ];
            })
            ->all();
    }

    private function statusLabel(string $status): string
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

    private function periodLabel(int $month, int $year): string
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
