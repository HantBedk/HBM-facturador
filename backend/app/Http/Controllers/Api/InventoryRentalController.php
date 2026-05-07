<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InventoryRentalResource;
use App\Models\InventoryAuditEvent;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryRental;
use App\Models\InventoryRentalLine;
use App\Models\PanelNotification;
use App\Support\Pagination;
use App\Services\PanelNotificationDispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryRentalController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $tenantCompanyId = $this->tenantCompanyIdFromRequest($request);
        $q = InventoryRental::query()
            ->with([
                'tenantCompany:id,nombre,nit',
                'createdBy:id,nombre,correo',
                'lines.owner:id,nombre',
                'lines.lot:id,name,sku',
            ])
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $q->where('status', $request->string('status')->toString());
        }
        $this->applyTenantContextFilter($q, $request, $tenantCompanyId);

        return InventoryRentalResource::collection(
            $q->paginate(Pagination::perPage($request))->withQueryString()
        );
    }

    public function store(Request $request): InventoryRentalResource
    {
        $actor = $request->user();
        if (! $actor->isAdminEquipo()) {
            abort(403, 'Solo administración puede registrar alquileres.');
        }

        $validated = $request->validate([
            'tenant_company_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
            'customer_name' => ['nullable', 'string', 'max:190'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.inventory_lot_id' => ['required', 'integer', 'exists:inventory_lots,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:999999'],
        ]);

        $tenantCompanyId = $this->tenantCompanyIdFromRequest($request, $validated);
        $rental = DB::transaction(function () use ($validated, $actor, $tenantCompanyId, $request) {
            $lotIds = collect($validated['lines'])->pluck('inventory_lot_id')->unique()->values()->all();
            $lots = InventoryLot::query()
                ->whereIn('id', $lotIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $rental = InventoryRental::query()->create([
                'created_by_user_id' => $actor->id,
                'tenant_company_id' => $tenantCompanyId,
                'status' => InventoryRental::STATUS_ACTIVE,
                'customer_name' => $validated['customer_name'] ?? null,
                'customer_phone' => $validated['customer_phone'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'started_at' => now(),
            ]);

            foreach ($validated['lines'] as $row) {
                $lotId = (int) $row['inventory_lot_id'];
                $qty = (int) $row['quantity'];
                $lot = $lots->get($lotId);
                if (! $lot) {
                    throw ValidationException::withMessages(['lines' => ["Lote {$lotId} no encontrado."]]);
                }
                if (! $this->lotBelongsToTenantContext($lot, $tenantCompanyId, $request)) {
                    throw ValidationException::withMessages([
                        'lines' => ["El lote «{$lot->name}» no pertenece a la empresa de contexto."],
                    ]);
                }
                if (! $lot->is_active) {
                    throw ValidationException::withMessages(['lines' => ["El producto «{$lot->name}» está inactivo."]]);
                }
                if (($lot->lifecycle_status ?? InventoryLot::LIFECYCLE_ACTIVO) !== InventoryLot::LIFECYCLE_ACTIVO) {
                    throw ValidationException::withMessages([
                        'lines' => ["El producto «{$lot->name}» no está disponible para alquiler (estado ciclo de vida)."],
                    ]);
                }
                if (! (bool) ($lot->allow_rental ?? false)) {
                    throw ValidationException::withMessages([
                        'lines' => ["El producto «{$lot->name}» no está habilitado para alquiler."],
                    ]);
                }
                if ($lot->quantity_available < 1) {
                    throw ValidationException::withMessages([
                        'lines' => ["El producto «{$lot->name}» no tiene unidades disponibles para alquiler."],
                    ]);
                }
                if ($lot->quantity_available < $qty) {
                    throw ValidationException::withMessages([
                        'lines' => ["Stock insuficiente para «{$lot->name}» (disponible: {$lot->quantity_available})."],
                    ]);
                }

                $unit = (string) $lot->unit_price;
                $lineTotal = bcmul($unit, (string) $qty, 2);

                InventoryRentalLine::query()->create([
                    'inventory_rental_id' => $rental->id,
                    'inventory_lot_id' => $lot->id,
                    'owner_user_id' => $lot->owner_user_id,
                    'tenant_company_id' => $tenantCompanyId,
                    'quantity' => $qty,
                    'unit_price' => $unit,
                    'line_total' => $lineTotal,
                ]);

                $lot->quantity_available = $lot->quantity_available - $qty;
                $lot->save();

                InventoryMovement::query()->create([
                    'inventory_lot_id' => $lot->id,
                    'user_id' => $actor->id,
                    'tenant_company_id' => $tenantCompanyId,
                    'type' => InventoryMovement::TYPE_ALQUILER_SALIDA,
                    'quantity_delta' => -$qty,
                    'inventory_sale_line_id' => null,
                    'note' => 'Salida por alquiler #'.$rental->id,
                    'created_at' => now(),
                ]);
            }
            $this->audit($request, $tenantCompanyId, 'inventory_rental', (int) $rental->id, 'create', null, [
                'status' => InventoryRental::STATUS_ACTIVE,
                'lines_count' => count($validated['lines']),
            ]);

            return $rental->fresh([
                'tenantCompany:id,nombre,nit',
                'createdBy:id,nombre,correo',
                'lines.owner:id,nombre',
                'lines.lot:id,name,sku',
            ]);
        });
        app(PanelNotificationDispatcher::class)->notifyAdmins(
            PanelNotification::TYPE_INVENTORY_ACTIVITY,
            $actor->nombre.' registró un alquiler de inventario #'.$rental->id.'.',
            ['link' => '/admin/inventario', 'inventory_rental_id' => $rental->id]
        );

        return new InventoryRentalResource($rental);
    }

    public function close(Request $request, InventoryRental $inventory_rental): InventoryRentalResource
    {
        $actor = $request->user();
        if (! $actor->isAdminEquipo()) {
            abort(403, 'Solo administración puede cerrar alquileres.');
        }
        if ($inventory_rental->status !== InventoryRental::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'rental' => ['Este alquiler ya está cerrado.'],
            ]);
        }
        $tenantCompanyId = $this->tenantCompanyIdFromRequest($request);
        if ($tenantCompanyId !== null && (int) ($inventory_rental->tenant_company_id ?? 0) !== $tenantCompanyId) {
            abort(403, 'El alquiler no pertenece al contexto de empresa indicado.');
        }
        if ($tenantCompanyId === null && $request->input('tenant_scope') === 'internal' && $inventory_rental->tenant_company_id !== null) {
            abort(403, 'El alquiler no pertenece al inventario interno.');
        }

        $rental = DB::transaction(function () use ($inventory_rental, $actor, $request) {
            $lines = InventoryRentalLine::query()
                ->where('inventory_rental_id', $inventory_rental->id)
                ->lockForUpdate()
                ->get();

            $lotIds = $lines->pluck('inventory_lot_id')->unique()->values()->all();
            $lots = InventoryLot::query()->whereIn('id', $lotIds)->lockForUpdate()->get()->keyBy('id');

            foreach ($lines as $line) {
                if ($line->returned_at !== null) {
                    continue;
                }
                $lot = $lots->get($line->inventory_lot_id);
                if (! $lot) {
                    continue;
                }
                // Devuelve unidades al disponible; el ciclo de vida del lote sigue siendo «activo» (no es baja por venta).
                $lot->quantity_available = $lot->quantity_available + (int) $line->quantity;
                $lot->save();

                $line->returned_at = now();
                $line->save();

                InventoryMovement::query()->create([
                    'inventory_lot_id' => $lot->id,
                    'user_id' => $actor->id,
                    'tenant_company_id' => $inventory_rental->tenant_company_id,
                    'type' => InventoryMovement::TYPE_ALQUILER_DEVOLUCION,
                    'quantity_delta' => (int) $line->quantity,
                    'inventory_sale_line_id' => null,
                    'note' => 'Devolución alquiler #'.$inventory_rental->id,
                    'created_at' => now(),
                ]);
            }

            $inventory_rental->status = InventoryRental::STATUS_CLOSED;
            $inventory_rental->closed_at = now();
            $inventory_rental->save();
            $this->audit($request, $inventory_rental->tenant_company_id, 'inventory_rental', (int) $inventory_rental->id, 'close', [
                'status' => InventoryRental::STATUS_ACTIVE,
            ], [
                'status' => InventoryRental::STATUS_CLOSED,
            ]);

            return $inventory_rental->fresh([
                'tenantCompany:id,nombre,nit',
                'createdBy:id,nombre,correo',
                'lines.owner:id,nombre',
                'lines.lot:id,name,sku',
            ]);
        });
        app(PanelNotificationDispatcher::class)->notifyAdmins(
            PanelNotification::TYPE_INVENTORY_ACTIVITY,
            $actor->nombre.' cerró el alquiler de inventario #'.$rental->id.'.',
            ['link' => '/admin/inventario', 'inventory_rental_id' => $rental->id]
        );

        return new InventoryRentalResource($rental);
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
