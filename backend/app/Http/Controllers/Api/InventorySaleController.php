<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InventorySaleResource;
use App\Models\AppSetting;
use App\Models\InventoryAuditEvent;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventorySale;
use App\Models\InventorySaleLine;
use App\Models\PanelNotification;
use App\Models\User;
use App\Support\Pagination;
use App\Services\PanelNotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventorySaleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $q = InventorySale::query()
            ->with([
                'tenantCompany:id,nombre,nit',
                'soldBy:id,nombre,correo',
                'lines.owner:id,nombre',
                'lines.lot:id,name,sku',
            ])
            ->whereNull('deleted_at')
            ->orderByDesc('id');
        $tenantCompanyId = $this->tenantCompanyIdFromRequest($request);

        if ($user->rol === User::ROL_EMPLEADO) {
            $q->where('sold_by_user_id', $user->id);
        }

        if ($request->filled('from')) {
            $q->whereDate('created_at', '>=', $request->string('from')->toString());
        }
        if ($request->filled('to')) {
            $q->whereDate('created_at', '<=', $request->string('to')->toString());
        }
        $this->applyTenantContextFilter($q, $request, $tenantCompanyId);

        return InventorySaleResource::collection(
            $q->paginate(Pagination::perPage($request))->withQueryString()
        );
    }

    public function store(Request $request): InventorySaleResource
    {
        $actor = $request->user();
        $this->assertCanRegisterInventorySale($actor);

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
            'tenant_company_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.inventory_lot_id' => ['required', 'integer', 'exists:inventory_lots,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:999999'],
        ]);

        $tenantCompanyId = $this->tenantCompanyIdFromRequest($request, $validated);
        $sale = DB::transaction(function () use ($validated, $actor, $tenantCompanyId, $request) {
            $lotIds = collect($validated['lines'])->pluck('inventory_lot_id')->unique()->values()->all();

            $lots = InventoryLot::query()
                ->whereIn('id', $lotIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $total = '0.00';

            $sale = InventorySale::query()->create([
                'sold_by_user_id' => $actor->id,
                'tenant_company_id' => $tenantCompanyId,
                'notes' => $validated['notes'] ?? null,
                'total_amount' => 0,
            ]);

            foreach ($validated['lines'] as $row) {
                $lotId = (int) $row['inventory_lot_id'];
                $qty = (int) $row['quantity'];

                $lot = $lots->get($lotId);
                if (! $lot) {
                    throw ValidationException::withMessages([
                        'lines' => ["Lote {$lotId} no encontrado."],
                    ]);
                }
                if (! $this->lotBelongsToTenantContext($lot, $tenantCompanyId, $request)) {
                    throw ValidationException::withMessages([
                        'lines' => ["El lote «{$lot->name}» no pertenece a la empresa de contexto."],
                    ]);
                }
                if (! $lot->is_active) {
                    throw ValidationException::withMessages([
                        'lines' => ["El producto «{$lot->name}» está inactivo."],
                    ]);
                }
                if (($lot->lifecycle_status ?? InventoryLot::LIFECYCLE_ACTIVO) !== InventoryLot::LIFECYCLE_ACTIVO) {
                    throw ValidationException::withMessages([
                        'lines' => ["El producto «{$lot->name}» no está disponible para venta (estado ciclo de vida)."],
                    ]);
                }
                if (! (bool) ($lot->allow_sale ?? true)) {
                    throw ValidationException::withMessages([
                        'lines' => ["El producto «{$lot->name}» no está habilitado para venta."],
                    ]);
                }
                if ($lot->quantity_available < 1) {
                    throw ValidationException::withMessages([
                        'lines' => ["El producto «{$lot->name}» no tiene stock disponible para venta."],
                    ]);
                }
                if ($lot->quantity_available < $qty) {
                    throw ValidationException::withMessages([
                        'lines' => ["Stock insuficiente para «{$lot->name}» (disponible: {$lot->quantity_available})."],
                    ]);
                }

                $unit = (string) $lot->unit_price;
                $lineTotal = $this->decimalMul($unit, (string) $qty, 2);
                $total = $this->decimalAdd($total, $lineTotal, 2);

                $line = InventorySaleLine::query()->create([
                    'inventory_sale_id' => $sale->id,
                    'inventory_lot_id' => $lot->id,
                    'owner_user_id' => $lot->owner_user_id,
                    'tenant_company_id' => $tenantCompanyId,
                    'quantity' => $qty,
                    'unit_price' => $unit,
                    'line_total' => $lineTotal,
                ]);

                $lot->quantity_available = $lot->quantity_available - $qty;
                $lot->save();

                if ((int) $lot->quantity_available === 0) {
                    $lot->markExhaustedAfterFullSale((int) $actor->id);
                }

                InventoryMovement::query()->create([
                    'inventory_lot_id' => $lot->id,
                    'user_id' => $actor->id,
                    'tenant_company_id' => $tenantCompanyId,
                    'type' => InventoryMovement::TYPE_VENTA,
                    'quantity_delta' => -$qty,
                    'inventory_sale_line_id' => $line->id,
                    'note' => null,
                    'created_at' => now(),
                ]);
            }

            $sale->total_amount = $total;
            $sale->save();
            $this->audit($request, $tenantCompanyId, 'inventory_sale', (int) $sale->id, 'create', null, [
                'total_amount' => (string) $sale->total_amount,
                'lines_count' => count($validated['lines']),
            ]);

            return $sale->fresh([
                'tenantCompany:id,nombre,nit',
                'soldBy:id,nombre,correo',
                'lines.owner:id,nombre',
                'lines.lot:id,name,sku',
            ]);
        });
        app(PanelNotificationDispatcher::class)->notifyAdmins(
            PanelNotification::TYPE_INVENTORY_ACTIVITY,
            $actor->nombre.' registró una venta de inventario #'.$sale->id.'.',
            ['link' => '/admin/inventario', 'inventory_sale_id' => $sale->id]
        );

        return new InventorySaleResource($sale);
    }

    /**
     * Anula una venta y devuelve existencias. Con factura asociada solo super_admin y motivo obligatorio.
     */
    public function destroy(Request $request, InventorySale $inventory_sale): JsonResponse
    {
        $actor = $request->user();
        $ownSaleRollback =
            $actor->rol === User::ROL_EMPLEADO
            && (int) $inventory_sale->sold_by_user_id === (int) $actor->id
            && $inventory_sale->invoice_id === null;

        if (! $actor->isAdminEquipo() && ! $ownSaleRollback) {
            abort(403, 'Solo administración puede anular ventas de inventario.');
        }

        $tenantCompanyId = $this->tenantCompanyIdFromRequest($request);
        if ($tenantCompanyId !== null && (int) ($inventory_sale->tenant_company_id ?? 0) !== $tenantCompanyId) {
            abort(403, 'La venta no pertenece al contexto de empresa indicado.');
        }
        if ($tenantCompanyId === null && $request->input('tenant_scope') === 'internal' && $inventory_sale->tenant_company_id !== null) {
            abort(403, 'La venta no pertenece al inventario interno.');
        }

        if ($inventory_sale->invoice_id !== null && $actor->rol !== User::ROL_SUPER_ADMIN) {
            abort(403, 'Esta venta está asociada a una factura. Solo super administración puede anularla indicando el motivo.');
        }

        $voidReason = null;
        if ($inventory_sale->invoice_id !== null) {
            $validated = $request->validate([
                'void_reason' => ['required', 'string', 'min:20', 'max:500'],
            ]);
            $voidReason = $validated['void_reason'];
        }

        DB::transaction(function () use ($inventory_sale, $actor, $request, $tenantCompanyId, $voidReason) {
            $lines = InventorySaleLine::query()
                ->where('inventory_sale_id', $inventory_sale->id)
                ->lockForUpdate()
                ->get();

            $lotIds = $lines->pluck('inventory_lot_id')->unique()->values()->all();
            $lots = InventoryLot::query()->whereIn('id', $lotIds)->lockForUpdate()->get()->keyBy('id');

            foreach ($lines as $line) {
                $lot = $lots->get($line->inventory_lot_id);
                if (! $lot) {
                    continue;
                }
                if (! $this->lotBelongsToTenantContext($lot, $tenantCompanyId, $request)) {
                    throw ValidationException::withMessages([
                        'sale' => ['El contexto de empresa no coincide con uno de los lotes.'],
                    ]);
                }
                $qty = (int) $line->quantity;
                $lot->restoreAfterSaleVoid($qty, (int) $actor->id);

                InventoryMovement::query()->create([
                    'inventory_lot_id' => $lot->id,
                    'user_id' => $actor->id,
                    'tenant_company_id' => $tenantCompanyId ?? $inventory_sale->tenant_company_id,
                    'type' => InventoryMovement::TYPE_VENTA_REVERSA,
                    'quantity_delta' => $qty,
                    'inventory_sale_line_id' => $line->id,
                    'note' => 'Anulación venta #'.$inventory_sale->id,
                    'created_at' => now(),
                ]);
            }

            $inventory_sale->voided_at = now();
            $inventory_sale->voided_by_user_id = $actor->id;
            $inventory_sale->void_reason = $voidReason;
            $inventory_sale->save();

            $this->audit($request, $tenantCompanyId, 'inventory_sale', (int) $inventory_sale->id, 'delete', [
                'total_amount' => (string) $inventory_sale->total_amount,
            ], null);

            $inventory_sale->delete();
        });

        return response()->json(['message' => 'Venta anulada y stock restaurado.']);
    }

    private function assertCanRegisterInventorySale(User $actor): void
    {
        if ($actor->isAdminEquipo()) {
            return;
        }
        if ($actor->rol === User::ROL_EMPLEADO && AppSetting::getBool(AppSetting::KEY_EMPLEADO_INVENTORY_VENTA_ENABLED, false)) {
            return;
        }
        abort(403, 'Solo administración puede registrar ventas de inventario (o habilite ventas para técnicos en configuración).');
    }

    private function decimalMul(string $a, string $b, int $scale = 2): string
    {
        if (function_exists('bcmul')) {
            return bcmul($a, $b, $scale);
        }

        return number_format((float) $a * (float) $b, $scale, '.', '');
    }

    private function decimalAdd(string $a, string $b, int $scale = 2): string
    {
        if (function_exists('bcadd')) {
            return bcadd($a, $b, $scale);
        }

        return number_format((float) $a + (float) $b, $scale, '.', '');
    }

    private function tenantCompanyIdFromRequest(Request $request, array $validated = []): ?int
    {
        $value = $validated['tenant_company_id'] ?? $request->input('tenant_company_id');
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function applyTenantContextFilter($query, Request $request, ?int $tenantCompanyId): void
    {
        if ($tenantCompanyId !== null) {
            $query->where('tenant_company_id', $tenantCompanyId);

            return;
        }

        if ($request->input('tenant_scope') === 'internal') {
            $query->whereNull('tenant_company_id');
        }
    }

    private function lotBelongsToTenantContext(InventoryLot $lot, ?int $tenantCompanyId, Request $request): bool
    {
        if ($tenantCompanyId !== null) {
            return (int) ($lot->tenant_company_id ?? 0) === $tenantCompanyId;
        }

        if ($request->input('tenant_scope') === 'internal') {
            return $lot->tenant_company_id === null;
        }

        return $lot->tenant_company_id === null;
    }

    private function audit(
        Request $request,
        ?int $tenantCompanyId,
        string $entityType,
        ?int $entityId,
        string $action,
        ?array $before,
        ?array $after
    ): void {
        InventoryAuditEvent::query()->create([
            'tenant_company_id' => $tenantCompanyId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'actor_user_id' => $request->user()?->id,
            'request_id' => $request->header('X-Request-Id'),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'before' => $before,
            'after' => $after,
            'occurred_at' => now(),
        ]);
    }
}
