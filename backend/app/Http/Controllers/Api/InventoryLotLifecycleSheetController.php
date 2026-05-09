<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesInventoryLotEmpleadoCustody;
use App\Http\Controllers\Controller;
use App\Models\InventoryAuditEvent;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryRental;
use App\Support\InventoryLotInternalCode;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class InventoryLotLifecycleSheetController extends Controller
{
    use AuthorizesInventoryLotEmpleadoCustody;

    public function __invoke(Request $request, InventoryLot $inventoryLot): Response|SymfonyResponse|JsonResponse
    {
        $actor = $request->user();
        $tenantCompanyId = $this->tenantCompanyIdFromRequest($request);
        $this->assertLotTenantScope($inventoryLot, $tenantCompanyId);

        if (! $actor->isAdminEquipo()) {
            if (! $this->empleadoCustodyMaintenanceReadAllowed($request, $inventoryLot, $tenantCompanyId)) {
                if ((int) $inventoryLot->owner_user_id !== (int) $actor->id) {
                    abort(403);
                }
                if ($inventoryLot->lifecycle_status !== InventoryLot::LIFECYCLE_ACTIVO) {
                    abort(403);
                }
            }
        }

        $unitsOnRent = (int) DB::table('inventory_rental_lines as irl')
            ->join('inventory_rentals as ir', 'ir.id', '=', 'irl.inventory_rental_id')
            ->whereNull('ir.deleted_at')
            ->where('ir.status', InventoryRental::STATUS_ACTIVE)
            ->whereNull('irl.returned_at')
            ->where('irl.inventory_lot_id', $inventoryLot->id)
            ->sum('irl.quantity');

        $hidePrices = $inventoryLot->tenant_company_id !== null;

        $movements = InventoryMovement::query()
            ->where('inventory_lot_id', $inventoryLot->id)
            ->with(['user:id,nombre'])
            ->orderBy('id')
            ->limit(100)
            ->get();

        $audits = collect();
        if ($actor->isAdminEquipo()) {
            $audits = InventoryAuditEvent::query()
                ->where('entity_type', 'inventory_lot')
                ->where('entity_id', $inventoryLot->id)
                ->with(['actor:id,nombre'])
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->limit(100)
                ->get();
        }

        $inventoryLot->loadMissing(['owner:id,nombre', 'tenantCompany:id,nombre,nit']);

        $internalCode = InventoryLotInternalCode::resolve(
            $inventoryLot->description,
            $inventoryLot->sku,
            (int) $inventoryLot->id,
            $inventoryLot->serial_number,
        );
        $filename = 'hoja-vida-'.preg_replace('/[^a-zA-Z0-9_-]/', '_', $internalCode).'.pdf';

        try {
            return Pdf::loadView('pdf.inventory_lot_sheet', [
                'lot' => $inventoryLot,
                'internalCode' => $internalCode,
                'unitsOnRent' => $unitsOnRent,
                'hidePrices' => $hidePrices,
                'movements' => $movements,
                'audits' => $audits,
                'generatedAt' => now(),
            ])->stream($filename);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug')
                    ? ('PDF: '.$e->getMessage())
                    : 'No se pudo generar el PDF.',
            ], 500);
        }
    }

    private function tenantCompanyIdFromRequest(Request $request): ?int
    {
        $value = $request->input('tenant_company_id');
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function assertLotTenantScope(InventoryLot $lot, ?int $tenantCompanyId): void
    {
        $requestedScope = request()->input('tenant_scope');
        if ($requestedScope === 'internal') {
            if ($lot->tenant_company_id !== null) {
                abort(403, 'El lote no pertenece al inventario interno.');
            }

            return;
        }

        if ($tenantCompanyId === null) {
            return;
        }
        if ((int) ($lot->tenant_company_id ?? 0) !== $tenantCompanyId) {
            abort(403, 'El lote no pertenece al contexto de empresa indicado.');
        }
    }
}
