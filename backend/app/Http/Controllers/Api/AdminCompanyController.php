<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\ServiceCatalogSuggestion;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminCompanyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Company::query()->orderByRaw('es_cliente_puntual asc')->orderBy('nombre');

        if ($request->filled('q')) {
            $raw = $request->string('q')->toString();
            $term = '%'.addcslashes($raw, '%_\\').'%';
            $q->where(function ($w) use ($term) {
                $w->where('nombre', 'like', $term)
                    ->orWhere('nit', 'like', $term)
                    ->orWhere('correo', 'like', $term);
            });
        }

        if ($request->filled('estado') && in_array($request->string('estado')->toString(), [
            Company::ESTADO_ACTIVO,
            Company::ESTADO_INACTIVO,
        ], true)) {
            $q->where('estado', $request->string('estado')->toString());
        }

        return CompanyResource::collection($q->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'factura_sigla' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/', Rule::unique('companies', 'factura_sigla')],
            'nit' => ['nullable', 'string', 'max:100', Rule::unique('companies', 'nit')],
            'telefono' => ['nullable', 'string', 'max:64'],
            'correo' => ['nullable', 'string', 'email', 'max:255'],
            'estado' => ['sometimes', Rule::in([Company::ESTADO_ACTIVO, Company::ESTADO_INACTIVO])],
        ]);

        $nombre = trim($data['nombre']);
        $this->assertNombreUnique($nombre);

        $company = Company::query()->create([
            'nombre' => $nombre,
            'factura_sigla' => strtoupper($data['factura_sigla']),
            'nit' => isset($data['nit']) && $data['nit'] !== '' ? trim($data['nit']) : null,
            'telefono' => isset($data['telefono']) && $data['telefono'] !== '' ? trim($data['telefono']) : null,
            'correo' => isset($data['correo']) && $data['correo'] !== '' ? trim($data['correo']) : null,
            'estado' => $data['estado'] ?? Company::ESTADO_ACTIVO,
        ]);

        return (new CompanyResource($company))->response()->setStatusCode(201);
    }

    public function update(Request $request, Company $company): CompanyResource
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'factura_sigla' => [
                'required',
                'string',
                'size:3',
                'regex:/^[A-Za-z]{3}$/',
                Rule::unique('companies', 'factura_sigla')->ignore($company->id),
            ],
            'nit' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('companies', 'nit')->ignore($company->id),
            ],
            'telefono' => ['nullable', 'string', 'max:64'],
            'correo' => ['nullable', 'string', 'email', 'max:255'],
            'estado' => ['required', Rule::in([Company::ESTADO_ACTIVO, Company::ESTADO_INACTIVO])],
        ]);

        $nombre = trim($data['nombre']);
        $this->assertNombreUnique($nombre, $company->id);

        $company->nombre = $nombre;
        $company->factura_sigla = strtoupper($data['factura_sigla']);
        $company->nit = isset($data['nit']) && $data['nit'] !== '' ? trim($data['nit']) : null;
        $company->telefono = isset($data['telefono']) && $data['telefono'] !== '' ? trim($data['telefono']) : null;
        $company->correo = isset($data['correo']) && $data['correo'] !== '' ? trim($data['correo']) : null;
        $company->estado = $data['estado'];
        $company->save();

        return new CompanyResource($company);
    }

    public function updateEstado(Request $request, Company $company): CompanyResource
    {
        $data = $request->validate([
            'estado' => ['required', Rule::in([Company::ESTADO_ACTIVO, Company::ESTADO_INACTIVO])],
        ]);

        $company->estado = $data['estado'];
        $company->save();

        return new CompanyResource($company);
    }

    public function destroy(Company $company): JsonResponse
    {
        $blockers = [];
        if ($company->services()->exists()) {
            $blockers[] = 'tiene servicios registrados';
        }
        if (ServiceCatalogSuggestion::query()->where('company_id', $company->id)->exists()) {
            $blockers[] = 'tiene propuestas de catálogo asociadas';
        }
        if ($blockers !== []) {
            throw ValidationException::withMessages([
                'company' => ['No se puede eliminar: la empresa '.implode(', ', $blockers).'.'],
            ]);
        }

        $company->delete();

        return response()->json(null, 204);
    }

    /**
     * KPIs de la empresa para un mes calendario (por defecto el mes anterior al actual).
     *
     * - Servicios: fecha de servicio dentro del mes.
     * - Facturas del periodo: period_year / period_month iguales a ese mes (criterio de facturación).
     * - Cobros en calendario: pagos con payment_date en ese mes (caja), para facturas de la empresa.
     * - Promedios: media aritmética de los últimos 12 meses (incluye el mes consultado).
     */
    public function monthlyDashboard(Request $request, Company $company): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
            'month' => ['sometimes', 'integer', 'min:1', 'max:12'],
        ]);

        $ref = isset($validated['year'], $validated['month'])
            ? Carbon::create((int) $validated['year'], (int) $validated['month'], 1)->startOfMonth()
            : Carbon::now()->subMonth()->startOfMonth();

        $year = (int) $ref->year;
        $month = (int) $ref->month;
        $monthStart = $ref->copy()->startOfMonth();
        $monthEnd = $ref->copy()->endOfMonth();
        $companyId = $company->id;

        $servicesQuery = Service::query()
            ->where('company_id', $companyId)
            ->whereDate('service_date', '>=', $monthStart->toDateString())
            ->whereDate('service_date', '<=', $monthEnd->toDateString());

        $servicesCount = (int) (clone $servicesQuery)->count();
        $servicesTotalAmount = $this->moneyString((clone $servicesQuery)->sum('amount'));

        $periodInvoices = Invoice::query()
            ->where('company_id', $companyId)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->withSum('payments', 'amount')
            ->get();

        $invoicesForPeriodCount = $periodInvoices->count();
        $invoicesForPeriodBilledTotal = $this->moneyString($periodInvoices->sum('total'));
        $paidOnPeriodInvoices = $this->moneyString($periodInvoices->sum(fn (Invoice $inv) => (float) ($inv->payments_sum_amount ?? 0)));
        $balanceOnPeriodInvoices = $this->moneyString($periodInvoices->sum(function (Invoice $inv) {
            $total = (float) $inv->total;
            $paid = (float) ($inv->payments_sum_amount ?? 0);

            return max(0, $total - $paid);
        }));

        $paymentsReceivedInCalendarMonth = $this->moneyString(
            (float) Payment::query()
                ->whereHas('invoice', fn ($q) => $q->where('company_id', $companyId))
                ->whereDate('payment_date', '>=', $monthStart->toDateString())
                ->whereDate('payment_date', '<=', $monthEnd->toDateString())
                ->sum('amount')
        );

        $billedSeries = [];
        $collectedSeries = [];
        for ($i = 0; $i < 12; $i++) {
            $d = $ref->copy()->subMonths($i);
            $y = (int) $d->year;
            $m = (int) $d->month;
            $billedSeries[] = (float) Invoice::query()
                ->where('company_id', $companyId)
                ->where('period_year', $y)
                ->where('period_month', $m)
                ->sum('total');
            $s = $d->copy()->startOfMonth();
            $e = $d->copy()->endOfMonth();
            $collectedSeries[] = (float) Payment::query()
                ->whereHas('invoice', fn ($q) => $q->where('company_id', $companyId))
                ->whereDate('payment_date', '>=', $s->toDateString())
                ->whereDate('payment_date', '<=', $e->toDateString())
                ->sum('amount');
        }

        $avgBilledPerMonth = $this->moneyString(array_sum($billedSeries) / 12);
        $avgCollectedPerMonth = $this->moneyString(array_sum($collectedSeries) / 12);

        return response()->json([
            'period' => [
                'year' => $year,
                'month' => $month,
                'month_start' => $monthStart->toDateString(),
                'month_end' => $monthEnd->toDateString(),
            ],
            'last_month' => [
                'services_count' => $servicesCount,
                'services_total_amount' => $servicesTotalAmount,
                'invoices_for_period_count' => $invoicesForPeriodCount,
                'invoices_for_period_billed_total' => $invoicesForPeriodBilledTotal,
                'invoices_for_period_paid_total' => $paidOnPeriodInvoices,
                'invoices_for_period_balance' => $balanceOnPeriodInvoices,
                'payments_received_in_calendar_month' => $paymentsReceivedInCalendarMonth,
            ],
            'averages_last_12_months' => [
                'avg_billed_per_month' => $avgBilledPerMonth,
                'avg_collected_per_month' => $avgCollectedPerMonth,
                'months_in_sample' => 12,
            ],
        ]);
    }

    private function moneyString(float|int|string|null $value): string
    {
        $n = is_numeric($value) ? (float) $value : 0.0;

        return number_format($n, 2, '.', '');
    }

    private function assertNombreUnique(string $nombre, ?int $ignoreId = null): void
    {
        $normalized = mb_strtolower($nombre);
        $q = Company::query()->whereRaw('LOWER(TRIM(nombre)) = ?', [$normalized]);
        if ($ignoreId !== null) {
            $q->where('id', '!=', $ignoreId);
        }
        if ($q->exists()) {
            throw ValidationException::withMessages([
                'nombre' => ['Ya existe una empresa con el mismo nombre (sin distinguir mayúsculas).'],
            ]);
        }
    }
}
