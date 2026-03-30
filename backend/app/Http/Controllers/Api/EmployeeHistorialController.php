<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeHistorialController extends Controller
{
    /**
     * Historial del técnico autenticado (mes calendario).
     */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();
        [$year, $month] = $this->validatedYearMonth($request);

        return response()->json($this->buildPayload($user, $year, $month));
    }

    /**
     * Historial de un empleado concreto (solo administración).
     */
    public function forUser(Request $request, User $user): JsonResponse
    {
        if ($user->rol !== User::ROL_EMPLEADO) {
            return response()->json(['message' => 'Solo se puede consultar el historial de usuarios con rol empleado.'], 422);
        }

        [$year, $month] = $this->validatedYearMonth($request);

        return response()->json($this->buildPayload($user, $year, $month));
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function validatedYearMonth(Request $request): array
    {
        $now = Carbon::now()->timezone(config('app.timezone'));

        $validated = $request->validate([
            'year' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
            'month' => ['sometimes', 'integer', 'min:1', 'max:12'],
        ]);

        $year = (int) ($validated['year'] ?? $now->year);
        $month = (int) ($validated['month'] ?? $now->month);

        return [$year, $month];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(User $employee, int $year, int $month): array
    {
        $tz = config('app.timezone');
        $start = Carbon::createFromDate($year, $month, 1, $tz)->startOfMonth();
        $end = (clone $start)->endOfMonth();

        $base = Service::query()
            ->where('user_id', $employee->id)
            ->visibles()
            ->whereBetween('service_date', [$start->toDateString(), $end->toDateString()]);

        $servicesCount = (clone $base)->count();
        $totalAmount = (string) (clone $base)->sum('amount');
        $avgPerService = $servicesCount > 0
            ? (string) round(((float) $totalAmount) / $servicesCount, 2)
            : '0';

        $distinctDays = (clone $base)
            ->select('service_date')
            ->orderBy('service_date')
            ->get()
            ->pluck('service_date')
            ->map(fn ($d) => $d?->toDateString())
            ->filter()
            ->unique()
            ->count();

        $services = (clone $base)
            ->with(['company:id,nombre'])
            ->orderByDesc('service_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Service $s) => $this->serviceRow($s))
            ->values()
            ->all();

        return [
            'employee' => [
                'id' => $employee->id,
                'nombre' => $employee->nombre,
                'correo' => $employee->correo,
            ],
            'period' => [
                'year' => $year,
                'month' => $month,
                'label' => $this->periodLabel($month, $year),
                'date_from' => $start->toDateString(),
                'date_to' => $end->toDateString(),
            ],
            'summary' => [
                'services_count' => $servicesCount,
                'total_amount' => $totalAmount,
                'avg_per_service' => $avgPerService,
                'distinct_service_days' => $distinctDays,
            ],
            'services' => $services,
            'generated_at' => Carbon::now($tz)->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceRow(Service $s): array
    {
        $s->loadMissing('company:id,nombre');

        return [
            'id' => $s->id,
            'code' => $s->code,
            'service_date' => $s->service_date?->format('Y-m-d'),
            'company_name' => $s->company?->nombre,
            'service_type' => $s->service_type,
            'description' => $s->description,
            'amount' => (string) $s->amount,
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
