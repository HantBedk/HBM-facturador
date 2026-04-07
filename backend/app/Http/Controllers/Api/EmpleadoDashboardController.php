<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
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
        $avgHistorial = $servicesCountHistorial > 0
            ? (string) round(((float) $totalAmountHistorial) / $servicesCountHistorial, 2)
            : '0';

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
                'avg_per_service_historial' => $avgHistorial,
                'last_registered' => $lastRegistered ? $this->serviceRow($lastRegistered) : null,
            ],
            'recent_services' => $recent,
            'generated_at' => $now->toIso8601String(),
        ]);
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
