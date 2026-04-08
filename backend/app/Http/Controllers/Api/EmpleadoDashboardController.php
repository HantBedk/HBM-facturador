<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EmpleadoDashboardController extends Controller
{
    /**
     * Resumen y actividad del técnico autenticado (solo servicios propios, mes calendario actual).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $now = Carbon::now()->timezone(config('app.timezone'));
        $year = (int) $now->year;
        $month = (int) $now->month;

        $monthBase = Service::query()
            ->where('user_id', $user->id)
            ->visibles()
            ->whereYear('service_date', $year)
            ->whereMonth('service_date', $month);

        $servicesCountMonth = (clone $monthBase)->count();
        $totalAmountMonth = $this->sumTechnicianReference(clone $monthBase);

        $historialBase = Service::query()
            ->where('user_id', $user->id)
            ->visibles();

        $servicesCountHistorial = (clone $historialBase)->count();
        $totalAmountHistorial = $this->sumTechnicianReference(clone $historialBase);

        $companyOwes = $this->companyOwesPendingTechnicianPayment($user);

        $lastRegistered = Service::query()
            ->where('user_id', $user->id)
            ->visibles()
            ->with(['company:id,nombre'])
            ->withSum('items', 'technician_line_amount')
            ->withCount('items')
            ->orderByDesc('created_at')
            ->first();

        $recent = Service::query()
            ->where('user_id', $user->id)
            ->visibles()
            ->with(['company:id,nombre'])
            ->withSum('items', 'technician_line_amount')
            ->withCount('items')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn (Service $s) => $this->serviceRow($s))
            ->values()
            ->all();

        return response()->json([
            'period' => [
                'year' => $year,
                'month' => $month,
                'label' => $this->periodLabel($month, $year),
            ],
            'summary' => [
                'services_count_month' => $servicesCountMonth,
                'total_amount_month' => $totalAmountMonth,
                'services_count_historial' => $servicesCountHistorial,
                'total_amount_historial' => $totalAmountHistorial,
                'company_owes' => $companyOwes,
                'last_registered' => $lastRegistered ? $this->serviceRow($lastRegistered) : null,
            ],
            'recent_services' => $recent,
            'generated_at' => $now->toIso8601String(),
        ]);
    }

    /**
     * Saldo de referencia pendiente de abono (misma regla que administración «pendiente de abono»):
     * servicios visibles del técnico sin `technician_paid_at` y con importe de referencia &gt; 0.
     * Incluye servicios aún no ligados a factura o solo en borrador; no exige factura emitida para mostrar deuda.
     *
     * `pending_invoices_count`: facturas distintas (no borrador) vinculadas a esos servicios, solo informativo.
     *
     * @return array{pending_total: string, pending_services_count: int, pending_invoices_count: int}
     */
    private function companyOwesPendingTechnicianPayment(User $user): array
    {
        $rows = Service::query()
            ->where('user_id', $user->id)
            ->visibles()
            ->whereNull('technician_paid_at')
            ->withSum('items', 'technician_line_amount')
            ->withCount('items')
            ->with(['invoices' => function ($q) {
                $q->select('invoices.id', 'invoices.status');
            }])
            ->get();

        $sum = 0.0;
        $svcCount = 0;
        $invoiceIds = collect();
        foreach ($rows as $s) {
            $v = $s->technicianReferenceTotalValue();
            if ($v <= 0.00001) {
                continue;
            }
            $sum += $v;
            $svcCount++;
            foreach ($s->invoices as $inv) {
                if ($inv->status !== Invoice::STATUS_BORRADOR) {
                    $invoiceIds->push((int) $inv->id);
                }
            }
        }

        return [
            'pending_total' => number_format($sum, 2, '.', ''),
            'pending_services_count' => $svcCount,
            'pending_invoices_count' => $invoiceIds->unique()->count(),
        ];
    }

    /**
     * Suma importes de referencia del técnico (sin margen/incremento de empresa), coherente con Service::technicianReferenceTotalValue().
     */
    private function sumTechnicianReference(Builder $base): string
    {
        $rows = (clone $base)->withSum('items', 'technician_line_amount')->withCount('items')->get();
        $sum = 0.0;
        foreach ($rows as $s) {
            $sum += $s->technicianReferenceTotalValue();
        }

        return number_format($sum, 2, '.', '');
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceRow(Service $s): array
    {
        $s->loadMissing('company:id,nombre');
        if (! $s->relationLoaded('items_count')) {
            $s->loadSum('items', 'technician_line_amount')->loadCount('items');
        }

        return [
            'id' => $s->id,
            'code' => $s->code,
            'service_date' => $s->service_date?->format('Y-m-d'),
            'company_name' => $s->company?->nombre,
            'description' => Str::limit((string) $s->description, 120),
            'amount' => number_format($s->technicianReferenceTotalValue(), 2, '.', ''),
            'created_at' => $s->created_at?->toIso8601String(),
        ];
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
