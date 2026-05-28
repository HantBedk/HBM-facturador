<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyRecurringService;
use App\Models\Service;
use App\Models\ServiceCatalog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Crea (si falta) los {@see Service} del mes a partir de plantillas activas por empresa.
 */
class CompanyRecurringInvoiceLinesService
{
    public function __construct(
        private ServiceCodeGenerator $codes,
    ) {}

    /**
     * @return list<int> IDs de servicios incluibles en el borrador del periodo.
     */
    public function ensureServicesForPeriod(Company $company, int $year, int $month, Carbon $start, Carbon $end): array
    {
        $templates = CompanyRecurringService::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->with('catalog:id,name,description')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($templates->isEmpty()) {
            return [];
        }

        $userId = $this->resolveBillingUserId();
        $dateStr = $end->toDateString();
        $startStr = $start->toDateString();
        $ids = [];

        foreach ($templates as $tpl) {
            $existing = Service::query()
                ->where('company_id', $company->id)
                ->where('recurring_service_id', $tpl->id)
                ->whereDate('service_date', '>=', $startStr)
                ->whereDate('service_date', '<=', $endStr)
                ->visibles()
                ->orderBy('id')
                ->first();

            if ($existing !== null) {
                $ids[] = (int) $existing->id;

                continue;
            }

            $catalog = $tpl->catalog;
            if ($catalog === null && $tpl->catalog_id !== null) {
                $catalog = ServiceCatalog::query()->find($tpl->catalog_id);
            }

            $description = $this->resolveDescription($tpl, $catalog);
            $serviceType = $this->resolveServiceType($tpl, $catalog);

            $codeKind = $this->resolveCodeKind($tpl);

            $row = DB::transaction(function () use ($company, $tpl, $userId, $dateStr, $description, $serviceType, $codeKind) {
                $code = $this->codes->nextForDate(Carbon::parse($dateStr, config('app.timezone')), $codeKind);

                return Service::query()->create([
                    'code' => $code,
                    'company_id' => $company->id,
                    'user_id' => $userId,
                    'catalog_id' => $tpl->catalog_id,
                    'recurring_service_id' => $tpl->id,
                    'client_name' => null,
                    'service_type' => $serviceType,
                    'description' => $description,
                    'amount' => $tpl->amount,
                    'service_date' => $dateStr,
                    'status' => Service::STATUS_ACTIVO,
                ]);
            });

            $ids[] = (int) $row->id;
        }

        return $ids;
    }

    /**
     * Materializa plantillas activas para cada mes calendario entre los extremos (inclusive).
     *
     * @return list<int> IDs de servicios recurrentes (existentes o recién creados) en ese rango.
     */
    public function ensureServicesForCalendarRange(Company $company, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $tz = config('app.timezone');
        $cursor = $rangeStart->copy()->timezone($tz)->startOfMonth();
        $last = $rangeEnd->copy()->timezone($tz)->endOfMonth();

        if ($cursor->gt($last)) {
            return [];
        }

        $ids = [];
        while ($cursor->lte($last)) {
            $y = (int) $cursor->year;
            $m = (int) $cursor->month;
            $start = $cursor->copy()->startOfMonth();
            $end = $cursor->copy()->endOfMonth();
            foreach ($this->ensureServicesForPeriod($company, $y, $m, $start, $end) as $id) {
                $ids[] = $id;
            }
            $cursor->addMonthNoOverflow();
        }

        return array_values(array_unique($ids));
    }

    private function resolveDescription(CompanyRecurringService $tpl, ?ServiceCatalog $catalog): string
    {
        $d = trim((string) ($tpl->description ?? ''));
        if ($d !== '') {
            return $d;
        }
        if ($catalog !== null) {
            $n = trim((string) ($catalog->name ?? ''));
            if ($n !== '') {
                return $n;
            }
            $cd = trim((string) ($catalog->description ?? ''));
            if ($cd !== '') {
                return $cd;
            }
        }

        return $this->defaultDescriptionForBillingKind($tpl->billing_kind ?? 'servicio');
    }

    private function defaultDescriptionForBillingKind(?string $kind): string
    {
        return match ($kind ?? 'servicio') {
            'venta' => 'Cargo fijo mensual (venta)',
            'alquiler' => 'Cargo fijo mensual (alquiler)',
            default => 'Servicio fijo mensual',
        };
    }

    private function resolveCodeKind(CompanyRecurringService $tpl): string
    {
        return match ($tpl->billing_kind ?? 'servicio') {
            'venta' => ServiceCodeGenerator::KIND_VENTA,
            'alquiler' => ServiceCodeGenerator::KIND_ALQUILER,
            default => ServiceCodeGenerator::KIND_SERVICIO,
        };
    }

    private function resolveServiceType(CompanyRecurringService $tpl, ?ServiceCatalog $catalog): ?string
    {
        $t = trim((string) ($tpl->service_type ?? ''));
        if ($t !== '') {
            return $t;
        }
        if ($catalog !== null) {
            $n = trim((string) ($catalog->name ?? ''));

            return $n !== '' ? $n : null;
        }

        return match ($tpl->billing_kind ?? 'servicio') {
            'venta' => 'Venta',
            'alquiler' => 'Alquiler',
            default => 'Servicio',
        };
    }

    private function resolveBillingUserId(): int
    {
        $configured = config('billing.recurring_services_user_id');
        if ($configured !== null && $configured !== '' && ctype_digit((string) $configured)) {
            $id = (int) $configured;
            if (User::query()->whereKey($id)->exists()) {
                return $id;
            }
        }

        $u = User::query()
            ->whereIn('rol', [User::ROL_SUPER_ADMIN, User::ROL_ADMIN])
            ->where('estado', User::ESTADO_ACTIVO)
            ->orderByRaw("CASE rol WHEN 'super_admin' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END")
            ->orderBy('id')
            ->first();

        if ($u === null) {
            throw new \RuntimeException(
                'No hay usuario administrador activo para asignar servicios fijos recurrentes. '.
                'Cree un admin o defina BILLING_RECURRING_SERVICES_USER_ID.'
            );
        }

        return (int) $u->id;
    }
}
