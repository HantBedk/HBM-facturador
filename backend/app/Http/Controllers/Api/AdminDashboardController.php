<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminDashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $now = Carbon::now()->timezone(config('app.timezone'));
        $year = (int) $now->year;
        $month = (int) $now->month;

        $metrics = array_merge($this->buildMetrics($year, $month), $this->technicianCashflowMetrics($year, $month));
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
     * Servicios del mes con importe técnico &gt; 0 y sin fecha de pago registrada; abonos a técnicos registrados en el mes; margen simple cobrado − abonos.
     *
     * @return array{
     *   technician_unpaid_services_count: int,
     *   technician_unpaid_services_total: string,
     *   technician_payouts_month: string,
     *   net_collected_after_technician_payouts: string
     * }
     */
    private function technicianCashflowMetrics(int $year, int $month): array
    {
        $tz = config('app.timezone');
        $start = Carbon::create($year, $month, 1, 0, 0, 0, $tz)->startOfDay();
        $end = $start->copy()->endOfMonth()->endOfDay();

        $unpaidRows = Service::query()
            ->visibles()
            ->whereNull('technician_paid_at')
            ->whereBetween('service_date', [$start->toDateString(), $end->copy()->toDateString()])
            ->withSum('items', 'technician_line_amount')
            ->withCount('items')
            ->get();

        $unpaidCount = 0;
        $unpaidTotal = 0.0;
        foreach ($unpaidRows as $s) {
            $v = $s->technicianReferenceTotalValue();
            if ($v > 0.00001) {
                $unpaidCount++;
                $unpaidTotal += $v;
            }
        }

        $payoutRows = Service::query()
            ->visibles()
            ->whereNotNull('technician_paid_at')
            ->whereBetween('technician_paid_at', [$start, $end])
            ->withSum('items', 'technician_line_amount')
            ->withCount('items')
            ->get();

        $payoutSum = 0.0;
        foreach ($payoutRows as $s) {
            $payoutSum += $s->technicianReferenceTotalValue();
        }

        $received = (float) Payment::query()
            ->whereYear('payment_date', $year)
            ->whereMonth('payment_date', $month)
            ->sum('amount');

        $net = $received - $payoutSum;

        return [
            'technician_unpaid_services_count' => $unpaidCount,
            'technician_unpaid_services_total' => number_format($unpaidTotal, 2, '.', ''),
            'technician_payouts_month' => number_format($payoutSum, 2, '.', ''),
            'net_collected_after_technician_payouts' => number_format($net, 2, '.', ''),
        ];
    }

    /**
     * @return array{invoiced_month: string, received_month: string, pending_collect: string, invoices_count_month: int, services_count_month: int}
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

        // Una sola consulta agregada (antes: cargaba todas las facturas en memoria).
        $pendingCollect = (float) (DB::table('invoices as i')
            ->where('i.status', '!=', Invoice::STATUS_BORRADOR)
            ->selectRaw(
                'COALESCE(SUM(GREATEST(0, CAST(i.total AS DECIMAL(14,2)) - COALESCE((SELECT SUM(amount) FROM payments WHERE invoice_id = i.id), 0))), 0) as p'
            )
            ->value('p') ?? 0);

        $invoicesCountMonth = Invoice::query()
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->where('status', '!=', Invoice::STATUS_BORRADOR)
            ->count();

        $servicesCountMonth = Service::query()
            ->visibles()
            ->whereYear('service_date', $year)
            ->whereMonth('service_date', $month)
            ->count();

        return [
            'invoiced_month' => $invoicedMonth,
            'received_month' => $receivedMonth,
            'pending_collect' => number_format($pendingCollect, 2, '.', ''),
            'invoices_count_month' => $invoicesCountMonth,
            'services_count_month' => $servicesCountMonth,
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
