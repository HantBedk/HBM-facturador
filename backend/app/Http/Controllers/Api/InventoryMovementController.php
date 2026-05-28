<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesInventoryLotEmpleadoCustody;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryMovementController extends Controller
{
    use AuthorizesInventoryLotEmpleadoCustody;

    public function __invoke(Request $request): JsonResponse
    {
        $actor = $request->user();
        $validated = $request->validate([
            'tenant_company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'inventory_lot_id' => ['nullable', 'integer', 'exists:inventory_lots,id'],
            'type' => ['nullable', 'string', 'max:64'],
        ]);

        $lotId = isset($validated['inventory_lot_id']) ? (int) $validated['inventory_lot_id'] : null;
        $tenantCompanyId = isset($validated['tenant_company_id']) ? (int) $validated['tenant_company_id'] : null;

        if ($lotId !== null && ! $actor->isAdminEquipo()) {
            $lot = InventoryLot::query()->findOrFail($lotId);
            if (! $this->empleadoCustodyMaintenanceReadAllowed($request, $lot, $tenantCompanyId)) {
                if ((int) $lot->owner_user_id !== (int) $actor->id || $lot->lifecycle_status !== InventoryLot::LIFECYCLE_ACTIVO) {
                    abort(403);
                }
            }
        }

        $q = InventoryMovement::query()
            ->with(['user:id,nombre,correo', 'lot:id,name,sku,description,serial_number,tenant_company_id,lifecycle_status'])
            ->orderByDesc('id')
            ->when($lotId, fn ($query) => $query->where('inventory_lot_id', $lotId))
            ->when($validated['type'] ?? null, fn ($query, $v) => $query->where('type', (string) $v));

        if ($actor->isAdminEquipo()) {
            if (($validated['tenant_company_id'] ?? null) !== null) {
                $q->where('tenant_company_id', (int) $validated['tenant_company_id']);
            } elseif ($request->input('tenant_scope') === 'internal') {
                $q->whereNull('tenant_company_id');
            }
        } elseif ($request->boolean('for_maintenance') && $tenantCompanyId !== null) {
            if (! Company::query()->whereKey($tenantCompanyId)->where('estado', Company::ESTADO_ACTIVO)->exists()) {
                abort(403, 'Empresa no disponible para este contexto.');
            }
            $q->where('tenant_company_id', $tenantCompanyId);
        } else {
            $q->whereHas('lot', function ($query) use ($actor) {
                $query->where('owner_user_id', $actor->id)
                    ->where('lifecycle_status', InventoryLot::LIFECYCLE_ACTIVO);
            });
        }

        return response()->json($q->paginate(Pagination::perPage($request))->withQueryString());
    }
}
