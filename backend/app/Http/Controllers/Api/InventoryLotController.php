<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\InventoryAuditEvent;
use App\Http\Resources\InventoryLotResource;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\PanelNotification;
use App\Models\InventorySaleLine;
use App\Models\User;
use App\Support\InventoryInternalHolders;
use App\Support\Pagination;
use App\Services\PanelNotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InventoryLotController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $q = InventoryLot::query()->with(['owner:id,nombre,correo,rol', 'tenantCompany:id,nombre,nit']);
        $tenantCompanyId = $this->tenantCompanyIdFromRequest($request);

        if (! $user->isAdminEquipo()) {
            $q->where('lifecycle_status', InventoryLot::LIFECYCLE_ACTIVO);
            $q->where('owner_user_id', $user->id);
        } elseif ($request->boolean('active_only')) {
            $q->where('lifecycle_status', InventoryLot::LIFECYCLE_ACTIVO);
        }
        if ($request->filled('lifecycle_status')) {
            $q->where('lifecycle_status', (string) $request->input('lifecycle_status'));
        }

        if ($request->filled('owner_user_id') && $user->isAdminEquipo()) {
            $q->where('owner_user_id', $request->integer('owner_user_id'));
        }
        $this->applyTenantContextFilter($q, $request, $tenantCompanyId);

        if ($request->filled('q')) {
            $raw = $request->string('q')->toString();
            $term = '%'.addcslashes($raw, '%_\\').'%';
            $q->where(function ($sub) use ($term) {
                $sub->where('name', 'like', $term)
                    ->orWhere('sku', 'like', $term);
            });
        }

        $q->orderByDesc('id');

        return InventoryLotResource::collection(
            $q->paginate(Pagination::perPage($request))->withQueryString()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor->isAdminEquipo()) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:64'],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:120'],
            'mac_address' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'quantity_available' => ['required', 'integer', 'min:1', 'max:999999'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'allow_sale' => ['sometimes', 'boolean'],
            'allow_rental' => ['sometimes', 'boolean'],
            'owner_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'tenant_company_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
        ]);
        $tenantCompanyId = $this->tenantCompanyIdFromRequest($request, $validated);

        $ownerId = array_key_exists('owner_user_id', $validated) && $validated['owner_user_id'] !== null
            ? (int) $validated['owner_user_id']
            : (int) $actor->id;

        $owner = User::query()->findOrFail($ownerId);
        $this->assertUserCanOwnInventory($owner);

        $requestedSku = array_key_exists('sku', $validated) && $validated['sku'] !== null
            ? trim((string) $validated['sku'])
            : '';
        $skuAtCreate = $requestedSku !== '' ? $requestedSku : null;
        $serialAtCreate = trim((string) ($validated['serial_number'] ?? ''));
        $macAtCreate = trim((string) ($validated['mac_address'] ?? ''));
        $fingerprintHash = $this->fingerprintHashFrom($serialAtCreate, $macAtCreate);
        $this->assertFingerprintNotBlocked($fingerprintHash);

        $lot = DB::transaction(function () use ($validated, $ownerId, $actor, $tenantCompanyId, $request, $skuAtCreate, $serialAtCreate, $macAtCreate, $fingerprintHash) {
            $lot = InventoryLot::query()->create([
                'owner_user_id' => $ownerId,
                'tenant_company_id' => $tenantCompanyId,
                'sku' => $skuAtCreate,
                'serial_number' => $serialAtCreate !== '' ? $serialAtCreate : null,
                'mac_address' => $macAtCreate !== '' ? $this->normalizeMacAddress($macAtCreate) : null,
                'fingerprint_hash' => $fingerprintHash,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'quantity_available' => (int) $validated['quantity_available'],
                'unit_price' => $validated['unit_price'],
                'is_active' => true,
                'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
                'lifecycle_status_changed_at' => now(),
                'allow_sale' => array_key_exists('allow_sale', $validated) ? (bool) $validated['allow_sale'] : true,
                'allow_rental' => array_key_exists('allow_rental', $validated) ? (bool) $validated['allow_rental'] : false,
            ]);

            if ($tenantCompanyId !== null && $lot->sku === null) {
                $this->assignTenantAssetSku($lot, $tenantCompanyId);
            }

            InventoryMovement::query()->create([
                'inventory_lot_id' => $lot->id,
                'user_id' => $actor->id,
                'tenant_company_id' => $tenantCompanyId,
                'type' => InventoryMovement::TYPE_ALTA,
                'quantity_delta' => $lot->quantity_available,
                'inventory_sale_line_id' => null,
                'note' => null,
                'created_at' => now(),
            ]);
            $this->audit($request, $tenantCompanyId, 'inventory_lot', (int) $lot->id, 'create', null, [
                'name' => $lot->name,
                'sku' => $lot->sku,
                'serial_number' => $lot->serial_number,
                'mac_address' => $lot->mac_address,
                'lifecycle_status' => $lot->lifecycle_status,
                'quantity_available' => $lot->quantity_available,
                'unit_price' => (string) $lot->unit_price,
            ]);

            return $lot->fresh();
        });

        $lot->load(['owner:id,nombre,correo,rol', 'tenantCompany:id,nombre,nit']);
        app(PanelNotificationDispatcher::class)->notifyAdmins(
            PanelNotification::TYPE_INVENTORY_ACTIVITY,
            $actor->nombre.' registró un activo de inventario: '.$lot->name.'.',
            ['link' => '/admin/inventario', 'inventory_lot_id' => $lot->id]
        );

        return (new InventoryLotResource($lot))->response()->setStatusCode(201);
    }

    public function update(Request $request, InventoryLot $inventoryLot): InventoryLotResource
    {
        $actor = $request->user();
        if (! $actor->isAdminEquipo()) {
            abort(403);
        }
        $this->authorizeLotManagement($actor, $inventoryLot);
        $tenantCompanyId = $this->tenantCompanyIdFromRequest($request);
        $this->assertLotTenantScope($inventoryLot, $tenantCompanyId);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:64'],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:120'],
            'mac_address' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'unit_price' => ['sometimes', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'allow_sale' => ['sometimes', 'boolean'],
            'allow_rental' => ['sometimes', 'boolean'],
            'quantity_available' => ['sometimes', 'integer', 'min:0', 'max:999999'],
            'adjustment_note' => ['required_with:quantity_available', 'nullable', 'string', 'max:500'],
        ]);

        if (array_key_exists('quantity_available', $validated)) {
            if (empty($validated['adjustment_note'])) {
                throw ValidationException::withMessages([
                    'adjustment_note' => ['Indique el motivo del ajuste de existencias.'],
                ]);
            }
        }

        DB::transaction(function () use ($inventoryLot, $validated, $actor) {
            $before = $inventoryLot->only([
                'name', 'sku', 'serial_number', 'mac_address', 'description', 'unit_price', 'is_active', 'lifecycle_status',
                'allow_sale', 'allow_rental', 'quantity_available',
            ]);
            if (array_key_exists('quantity_available', $validated)) {
                $newQty = (int) $validated['quantity_available'];
                $delta = $newQty - (int) $inventoryLot->quantity_available;
                if ($delta !== 0) {
                    InventoryMovement::query()->create([
                        'inventory_lot_id' => $inventoryLot->id,
                        'user_id' => $actor->id,
                        'tenant_company_id' => $inventoryLot->tenant_company_id,
                        'type' => InventoryMovement::TYPE_AJUSTE,
                        'quantity_delta' => $delta,
                        'inventory_sale_line_id' => null,
                        'note' => $validated['adjustment_note'],
                        'created_at' => now(),
                    ]);
                }
                $inventoryLot->quantity_available = $newQty;
            }

            foreach (['name', 'sku', 'description', 'unit_price', 'is_active', 'allow_sale', 'allow_rental'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $inventoryLot->{$field} = $validated[$field];
                }
            }
            if (array_key_exists('serial_number', $validated)) {
                $serial = trim((string) ($validated['serial_number'] ?? ''));
                $inventoryLot->serial_number = $serial !== '' ? $serial : null;
            }
            if (array_key_exists('mac_address', $validated)) {
                $macRaw = trim((string) ($validated['mac_address'] ?? ''));
                $inventoryLot->mac_address = $macRaw !== '' ? $this->normalizeMacAddress($macRaw) : null;
            }
            if ($inventoryLot->lifecycle_status === InventoryLot::LIFECYCLE_BAJA) {
                $inventoryLot->is_active = false;
                $inventoryLot->allow_sale = false;
                $inventoryLot->allow_rental = false;
            } elseif (! $inventoryLot->is_active) {
                $inventoryLot->lifecycle_status = InventoryLot::LIFECYCLE_BAJA;
            }
            if ($inventoryLot->is_active && $inventoryLot->lifecycle_status === InventoryLot::LIFECYCLE_BAJA) {
                throw ValidationException::withMessages([
                    'lifecycle_status' => ['Un equipo dado de baja no puede reactivarse desde esta operación.'],
                ]);
            }
            $inventoryLot->fingerprint_hash = $this->fingerprintHashFrom(
                (string) ($inventoryLot->serial_number ?? ''),
                (string) ($inventoryLot->mac_address ?? '')
            );
            $this->assertFingerprintNotBlocked($inventoryLot->fingerprint_hash, (int) $inventoryLot->id);

            $inventoryLot->save();
            $this->audit(request(), $inventoryLot->tenant_company_id, 'inventory_lot', (int) $inventoryLot->id, 'update', $before, [
                'name' => $inventoryLot->name,
                'sku' => $inventoryLot->sku,
                'serial_number' => $inventoryLot->serial_number,
                'mac_address' => $inventoryLot->mac_address,
                'description' => $inventoryLot->description,
                'unit_price' => (string) $inventoryLot->unit_price,
                'is_active' => $inventoryLot->is_active,
                'lifecycle_status' => $inventoryLot->lifecycle_status,
                'allow_sale' => $inventoryLot->allow_sale,
                'allow_rental' => $inventoryLot->allow_rental,
                'quantity_available' => $inventoryLot->quantity_available,
            ]);
        });
        app(PanelNotificationDispatcher::class)->notifyAdmins(
            PanelNotification::TYPE_INVENTORY_ACTIVITY,
            $actor->nombre.' actualizó un activo de inventario: '.$inventoryLot->name.'.',
            ['link' => '/admin/inventario', 'inventory_lot_id' => $inventoryLot->id]
        );

        return new InventoryLotResource($inventoryLot->fresh(['owner:id,nombre,correo,rol', 'tenantCompany:id,nombre,nit']));
    }

    public function destroy(Request $request, InventoryLot $inventoryLot): JsonResponse
    {
        $actor = $request->user();
        if (! $actor->isAdminEquipo()) {
            abort(403);
        }
        $this->authorizeLotManagement($actor, $inventoryLot);
        $tenantCompanyId = $this->tenantCompanyIdFromRequest($request);
        $this->assertLotTenantScope($inventoryLot, $tenantCompanyId);

        if (InventorySaleLine::query()->where('inventory_lot_id', $inventoryLot->id)->exists()) {
            throw ValidationException::withMessages([
                'inventory_lot' => ['No se puede eliminar un lote con ventas registradas. Desactive el ítem en su lugar.'],
            ]);
        }

        $before = $inventoryLot->only([
            'name', 'sku', 'description', 'unit_price', 'is_active', 'quantity_available', 'tenant_company_id',
        ]);
        $inventoryLot->delete();
        $this->audit($request, $inventoryLot->tenant_company_id, 'inventory_lot', (int) $inventoryLot->id, 'delete', $before, null);
        app(PanelNotificationDispatcher::class)->notifyAdmins(
            PanelNotification::TYPE_INVENTORY_ACTIVITY,
            $actor->nombre.' eliminó un activo de inventario: '.$before['name'].'.',
            ['link' => '/admin/inventario']
        );

        return response()->json(['ok' => true]);
    }

    /**
     * Código único por artículo para inventario de empresa cliente: {sigla|C{id}}-A{lotId}.
     */
    private function assignTenantAssetSku(InventoryLot $lot, int $tenantCompanyId): void
    {
        $company = Company::query()->find($tenantCompanyId);
        $prefix = 'C'.$tenantCompanyId;
        if ($company && $company->factura_sigla) {
            $clean = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $company->factura_sigla) ?? '');
            if ($clean !== '') {
                $prefix = strlen($clean) > 12 ? substr($clean, 0, 12) : $clean;
            }
        }
        $lot->sku = sprintf('%s-A%06d', $prefix, $lot->id);
        $lot->save();
    }

    private function authorizeLotManagement(User $actor, InventoryLot $lot): void
    {
        if ($actor->rol === User::ROL_SUPER_ADMIN) {
            return;
        }
        if ((int) $lot->owner_user_id !== (int) $actor->id) {
            abort(403, 'Solo el titular del inventario o el super administrador pueden modificar este lote.');
        }
    }

    private function assertUserCanOwnInventory(User $user): void
    {
        if (! InventoryInternalHolders::userMayHoldInventory($user)) {
            throw ValidationException::withMessages([
                'owner_user_id' => ['El titular no está autorizado. Revise Titulares de inventario en Configuración.'],
            ]);
        }
    }

    private function tenantCompanyIdFromRequest(Request $request, array $validated = []): ?int
    {
        $value = $validated['tenant_company_id'] ?? $request->input('tenant_company_id');
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

    private function normalizeMacAddress(?string $raw): ?string
    {
        $value = Str::upper(preg_replace('/[^a-fA-F0-9]/', '', (string) $raw) ?? '');
        if ($value === '') {
            return null;
        }

        return implode(':', str_split(substr($value, 0, 12), 2));
    }

    private function fingerprintHashFrom(?string $serial, ?string $mac): ?string
    {
        $serialNorm = Str::upper(trim((string) $serial));
        $macNorm = $this->normalizeMacAddress($mac);
        if ($serialNorm === '' && ($macNorm === null || $macNorm === '')) {
            return null;
        }

        return hash('sha256', $serialNorm.'|'.($macNorm ?? ''));
    }

    private function assertFingerprintNotBlocked(?string $fingerprintHash, ?int $exceptLotId = null): void
    {
        if ($fingerprintHash === null || $fingerprintHash === '') {
            return;
        }
        $q = InventoryLot::query()
            ->where('lifecycle_status', InventoryLot::LIFECYCLE_BAJA)
            ->where('fingerprint_hash', $fingerprintHash);
        if ($exceptLotId !== null) {
            $q->where('id', '!=', $exceptLotId);
        }
        if ($q->exists()) {
            throw ValidationException::withMessages([
                'serial_number' => ['La huella técnica del equipo pertenece a un activo dado de baja y no puede reutilizarse.'],
            ]);
        }
    }
}
