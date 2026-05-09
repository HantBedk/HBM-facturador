<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Company;
use App\Models\InventoryLot;
use Illuminate\Http\Request;

/**
 * Lectura de inventario de custodia (tenant) por técnico al registrar mantenimiento:
 * requiere for_maintenance=1 + tenant_company_id acorde al lote y empresa activa.
 */
trait AuthorizesInventoryLotEmpleadoCustody
{
    protected function empleadoCustodyMaintenanceReadAllowed(
        Request $request,
        InventoryLot $lot,
        ?int $tenantCompanyId
    ): bool {
        $user = $request->user();
        if ($user->isAdminEquipo()) {
            return false;
        }
        if (! $request->boolean('for_maintenance')) {
            return false;
        }
        if ($tenantCompanyId === null) {
            return false;
        }
        if (! Company::query()->whereKey($tenantCompanyId)->where('estado', Company::ESTADO_ACTIVO)->exists()) {
            return false;
        }

        return (int) ($lot->tenant_company_id ?? 0) === $tenantCompanyId
            && $lot->lifecycle_status === InventoryLot::LIFECYCLE_ACTIVO;
    }
}
