<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmployeeHistorialController extends Controller
{
    /**
     * Historial de un empleado concreto (solo administración).
     */
    public function forUser(Request $request, User $user): JsonResponse
    {
        if ($user->rol !== User::ROL_EMPLEADO) {
            return response()->json(['message' => 'Solo se puede consultar el historial de usuarios con rol empleado.'], 422);
        }

        [$year, $month] = $this->validatedYearMonth($request);
        $filters = $this->validatedHistorialFilters($request);

        return response()->json($this->buildPayload($user, $year, $month, $filters));
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
     * @return array{company_id: int|null, q: string}
     */
    private function validatedHistorialFilters(Request $request): array
    {
        $validated = $request->validate([
            'company_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        $q = isset($validated['q']) ? trim($validated['q']) : '';

        return [
            'company_id' => isset($validated['company_id']) ? (int) $validated['company_id'] : null,
            'q' => $q,
        ];
    }

    /**
     * @param  array{company_id: int|null, q: string}  $filters
     * @return array<string, mixed>
     */
    private function buildPayload(User $employee, int $year, int $month, array $filters): array
    {
        $tz = config('app.timezone');
        $start = Carbon::createFromDate($year, $month, 1, $tz)->startOfMonth();
        $end = (clone $start)->endOfMonth();

        $base = Service::query()
            ->where('user_id', $employee->id)
            ->visibles()
            ->whereBetween('service_date', [$start->toDateString(), $end->toDateString()]);

        if ($filters['company_id'] !== null) {
            $base->where('company_id', $filters['company_id']);
        }

        if ($filters['q'] !== '') {
            $term = '%'.addcslashes($filters['q'], '%_\\').'%';
            $base->where(function ($w) use ($term) {
                $w->where('description', 'like', $term)
                    ->orWhere('client_name', 'like', $term)
                    ->orWhere('service_type', 'like', $term)
                    ->orWhere('code', 'like', $term);
            });
        }

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

        $maxAmount = $servicesCount > 0
            ? (string) (clone $base)->max('amount')
            : '0';

        $busiestDay = $this->busiestDaySummary(clone $base);

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
                'rol' => $employee->rol,
                'estado' => $employee->estado,
                'rol_label' => $this->userRolLabel($employee->rol),
                'estado_label' => $employee->estado === User::ESTADO_ACTIVO ? 'Activo' : 'Inactivo',
            ],
            'period' => [
                'year' => $year,
                'month' => $month,
                'label' => $this->periodLabel($month, $year),
                'date_from' => $start->toDateString(),
                'date_to' => $end->toDateString(),
            ],
            'filters_applied' => [
                'company_id' => $filters['company_id'],
                'q' => $filters['q'] !== '' ? $filters['q'] : null,
            ],
            'summary' => [
                'services_count' => $servicesCount,
                'total_amount' => $totalAmount,
                'avg_per_service' => $avgPerService,
                'distinct_service_days' => $distinctDays,
                'max_service_amount' => $maxAmount,
                'busiest_day' => $busiestDay,
            ],
            'services' => $services,
            'generated_at' => Carbon::now($tz)->toIso8601String(),
        ];
    }

    /**
     * Día del mes (en el conjunto filtrado) con más servicios registrados.
     *
     * @return array{date: string, services_count: int}|null
     */
    private function busiestDaySummary($baseQuery): ?array
    {
        $row = (clone $baseQuery)
            ->select('service_date', DB::raw('COUNT(*) as svc_count'))
            ->groupBy('service_date')
            ->orderByDesc('svc_count')
            ->orderByDesc('service_date')
            ->first();

        if ($row === null || ! isset($row->service_date)) {
            return null;
        }

        return [
            'date' => $row->service_date->format('Y-m-d'),
            'services_count' => (int) $row->svc_count,
        ];
    }

    private function userRolLabel(string $rol): string
    {
        return match ($rol) {
            User::ROL_EMPLEADO => 'Empleado',
            User::ROL_ADMIN => 'Administrador',
            User::ROL_SUPER_ADMIN => 'Super administrador',
            default => $rol,
        };
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
