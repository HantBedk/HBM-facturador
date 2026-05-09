<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesInventoryLotEmpleadoCustody;
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
use App\Support\InventoryLotInternalCode;
use App\Support\Pagination;
use App\Services\InventoryLotCsvImportService;
use App\Services\PanelNotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\InventoryRental;
use Illuminate\Validation\ValidationException;

class InventoryLotController extends Controller
{
    use AuthorizesInventoryLotEmpleadoCustody;

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'created_from' => ['nullable', 'date'],
            'created_to' => ['nullable', 'date', 'after_or_equal:created_from'],
            'sort' => ['nullable', 'string', 'in:id,created_at,name,quantity_available,serial_number,brand,asset_type,site_label,warranty_until'],
            'sort_dir' => ['nullable', 'string', 'in:asc,desc'],
            'asset_type' => ['sometimes', 'nullable', 'string', 'max:120'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:120'],
            'site_label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'physical_condition' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $user = $request->user();
        $q = InventoryLot::query()->with(['owner:id,nombre,correo,rol', 'tenantCompany:id,nombre,nit']);
        $tenantCompanyId = $this->tenantCompanyIdFromRequest($request);

        if (! $user->isAdminEquipo()) {
            if ($request->boolean('for_maintenance') && $tenantCompanyId !== null) {
                if (! Company::query()->whereKey($tenantCompanyId)->where('estado', Company::ESTADO_ACTIVO)->exists()) {
                    abort(403, 'Empresa no disponible para este contexto.');
                }
                $q->where('lifecycle_status', InventoryLot::LIFECYCLE_ACTIVO);
            } else {
                $q->where('lifecycle_status', InventoryLot::LIFECYCLE_ACTIVO);
                $q->where('owner_user_id', $user->id);
            }
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

        if ($request->filled('asset_type')) {
            $q->where('asset_type', 'like', '%'.addcslashes($request->string('asset_type')->toString(), '%_\\').'%');
        }
        if ($request->filled('brand')) {
            $q->where('brand', 'like', '%'.addcslashes($request->string('brand')->toString(), '%_\\').'%');
        }
        if ($request->filled('site_label')) {
            $q->where('site_label', 'like', '%'.addcslashes($request->string('site_label')->toString(), '%_\\').'%');
        }
        if ($request->filled('physical_condition')) {
            $q->where('physical_condition', 'like', '%'.addcslashes($request->string('physical_condition')->toString(), '%_\\').'%');
        }

        if ($request->filled('q')) {
            $raw = $request->string('q')->toString();
            $term = '%'.addcslashes($raw, '%_\\').'%';
            $q->where(function ($sub) use ($term) {
                $sub->where('name', 'like', $term)
                    ->orWhere('serial_number', 'like', $term)
                    ->orWhere('mac_address', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhere('asset_type', 'like', $term)
                    ->orWhere('asset_subtype', 'like', $term)
                    ->orWhere('brand', 'like', $term)
                    ->orWhere('model', 'like', $term)
                    ->orWhere('site_label', 'like', $term)
                    ->orWhere('area_label', 'like', $term)
                    ->orWhere('responsible_name', 'like', $term);
            });
        }

        if ($request->filled('created_from')) {
            $q->whereDate('created_at', '>=', $request->date('created_from')->format('Y-m-d'));
        }
        if ($request->filled('created_to')) {
            $q->whereDate('created_at', '<=', $request->date('created_to')->format('Y-m-d'));
        }

        $sort = (string) $request->input('sort', 'id');
        $dir = strtolower((string) $request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $q->orderBy($sort, $dir);
        if ($sort !== 'id') {
            $q->orderByDesc('id');
        }

        $paginator = $q->paginate(Pagination::perPage($request))->withQueryString();
        $ids = $paginator->getCollection()->pluck('id')->filter()->values();
        if ($ids->isNotEmpty()) {
            $sums = DB::table('inventory_rental_lines as irl')
                ->join('inventory_rentals as ir', 'ir.id', '=', 'irl.inventory_rental_id')
                ->whereNull('ir.deleted_at')
                ->where('ir.status', InventoryRental::STATUS_ACTIVE)
                ->whereNull('irl.returned_at')
                ->whereIn('irl.inventory_lot_id', $ids->all())
                ->groupBy('irl.inventory_lot_id')
                ->selectRaw('irl.inventory_lot_id as lid, SUM(irl.quantity) as u')
                ->pluck('u', 'lid');
            foreach ($paginator->getCollection() as $lot) {
                $lot->setAttribute('units_on_rent', (int) ($sums[$lot->id] ?? 0));
            }
        }

        return InventoryLotResource::collection($paginator);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor->isAdminEquipo()) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:120'],
            'mac_address' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'quantity_available' => ['required', 'integer', 'min:1', 'max:999999'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'allow_sale' => ['sometimes', 'boolean'],
            'allow_rental' => ['sometimes', 'boolean'],
            'owner_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'tenant_company_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
            'asset_type' => ['nullable', 'string', 'max:120'],
            'asset_subtype' => ['nullable', 'string', 'max:120'],
            'brand' => ['nullable', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:120'],
            'site_label' => ['nullable', 'string', 'max:255'],
            'area_label' => ['nullable', 'string', 'max:255'],
            'physical_condition' => ['nullable', 'string', 'max:120'],
            'warranty_until' => ['nullable', 'date'],
            'purchase_date' => ['nullable', 'date'],
            'custody_received_at' => ['nullable', 'date'],
            'responsible_name' => ['nullable', 'string', 'max:255'],
            'responsible_role' => ['nullable', 'string', 'max:120'],
        ]);
        $tenantCompanyId = $this->tenantCompanyIdFromRequest($request, $validated);

        $ownerId = array_key_exists('owner_user_id', $validated) && $validated['owner_user_id'] !== null
            ? (int) $validated['owner_user_id']
            : (int) $actor->id;

        $owner = User::query()->findOrFail($ownerId);
        $this->assertUserCanOwnInventory($owner);

        $fromDesc = InventoryLotInternalCode::extractFromDescription($validated['description'] ?? null);
        $serialAtCreate = trim((string) ($validated['serial_number'] ?? ''));
        $skuAtCreate = $serialAtCreate !== '' ? $serialAtCreate : (($fromDesc !== null && $fromDesc !== '') ? $fromDesc : null);
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
                'asset_type' => $validated['asset_type'] ?? null,
                'asset_subtype' => $validated['asset_subtype'] ?? null,
                'brand' => $validated['brand'] ?? null,
                'model' => $validated['model'] ?? null,
                'site_label' => $validated['site_label'] ?? null,
                'area_label' => $validated['area_label'] ?? null,
                'physical_condition' => $validated['physical_condition'] ?? null,
                'warranty_until' => $validated['warranty_until'] ?? null,
                'purchase_date' => $validated['purchase_date'] ?? null,
                'custody_received_at' => $validated['custody_received_at'] ?? null,
                'responsible_name' => $validated['responsible_name'] ?? null,
                'responsible_role' => $validated['responsible_role'] ?? null,
                'quantity_available' => (int) $validated['quantity_available'],
                'unit_price' => $validated['unit_price'],
                'is_active' => true,
                'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
                'lifecycle_status_changed_at' => now(),
                // Inventario de empresa cliente = custodia: nunca comercializable por venta/alquiler.
                'allow_sale' => $tenantCompanyId !== null
                    ? false
                    : (array_key_exists('allow_sale', $validated) ? (bool) $validated['allow_sale'] : true),
                'allow_rental' => $tenantCompanyId !== null
                    ? false
                    : (array_key_exists('allow_rental', $validated) ? (bool) $validated['allow_rental'] : false),
            ]);

            if ($tenantCompanyId !== null && $lot->sku === null) {
                $this->assignTenantAssetSku($lot, $tenantCompanyId);
            } elseif ($lot->sku === null) {
                $lot->sku = sprintf('INT-A%06d', $lot->id);
                $lot->save();
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
                'inventory_reference' => $lot->sku,
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

    public function show(Request $request, InventoryLot $inventoryLot): InventoryLotResource
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

        $inventoryLot->setAttribute('units_on_rent', $unitsOnRent);
        $inventoryLot->load(['owner:id,nombre,correo,rol', 'tenantCompany:id,nombre,nit']);

        return new InventoryLotResource($inventoryLot);
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
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:120'],
            'mac_address' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'unit_price' => ['sometimes', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'allow_sale' => ['sometimes', 'boolean'],
            'allow_rental' => ['sometimes', 'boolean'],
            'quantity_available' => ['sometimes', 'integer', 'min:0', 'max:999999'],
            'adjustment_note' => ['required_with:quantity_available', 'nullable', 'string', 'max:500'],
            'asset_type' => ['sometimes', 'nullable', 'string', 'max:120'],
            'asset_subtype' => ['sometimes', 'nullable', 'string', 'max:120'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:120'],
            'model' => ['sometimes', 'nullable', 'string', 'max:120'],
            'site_label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'area_label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'physical_condition' => ['sometimes', 'nullable', 'string', 'max:120'],
            'warranty_until' => ['sometimes', 'nullable', 'date'],
            'purchase_date' => ['sometimes', 'nullable', 'date'],
            'custody_received_at' => ['sometimes', 'nullable', 'date'],
            'responsible_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'responsible_role' => ['sometimes', 'nullable', 'string', 'max:120'],
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
                'name', 'serial_number', 'mac_address', 'description', 'unit_price', 'is_active', 'lifecycle_status',
                'allow_sale', 'allow_rental', 'quantity_available',
                'asset_type', 'asset_subtype', 'brand', 'model', 'site_label', 'area_label', 'physical_condition',
                'warranty_until', 'purchase_date', 'custody_received_at', 'responsible_name', 'responsible_role',
            ]);
            $before['inventory_reference'] = $inventoryLot->sku;
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

            foreach ([
                'name', 'description', 'unit_price', 'is_active', 'allow_sale', 'allow_rental',
                'asset_type', 'asset_subtype', 'brand', 'model', 'site_label', 'area_label', 'physical_condition',
                'warranty_until', 'purchase_date', 'custody_received_at', 'responsible_name', 'responsible_role',
            ] as $field) {
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
            if ($inventoryLot->tenant_company_id !== null) {
                // Custodia por empresa: no se habilita comercialización aunque llegue en payload.
                $inventoryLot->allow_sale = false;
                $inventoryLot->allow_rental = false;
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
                'inventory_reference' => $inventoryLot->sku,
                'serial_number' => $inventoryLot->serial_number,
                'mac_address' => $inventoryLot->mac_address,
                'description' => $inventoryLot->description,
                'asset_type' => $inventoryLot->asset_type,
                'asset_subtype' => $inventoryLot->asset_subtype,
                'brand' => $inventoryLot->brand,
                'model' => $inventoryLot->model,
                'site_label' => $inventoryLot->site_label,
                'area_label' => $inventoryLot->area_label,
                'physical_condition' => $inventoryLot->physical_condition,
                'warranty_until' => $inventoryLot->warranty_until?->format('Y-m-d'),
                'purchase_date' => $inventoryLot->purchase_date?->format('Y-m-d'),
                'custody_received_at' => $inventoryLot->custody_received_at?->format('Y-m-d'),
                'responsible_name' => $inventoryLot->responsible_name,
                'responsible_role' => $inventoryLot->responsible_role,
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

    /**
     * Importación CSV de activos en custodia (empresa cliente). multipart: file, tenant_company_id, dry_run.
     */
    public function importLots(Request $request, InventoryLotCsvImportService $importer): JsonResponse
    {
        $actor = $request->user();
        if (! $actor->isAdminEquipo()) {
            abort(403);
        }
        $validated = $request->validate([
            'tenant_company_id' => ['required', 'integer', 'exists:companies,id'],
            'dry_run' => ['required', 'boolean'],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:8192'],
        ]);
        $tenantId = (int) $validated['tenant_company_id'];
        $dryRun = (bool) $validated['dry_run'];
        $out = $importer->run($request->file('file'), $tenantId, $actor, $request, $dryRun);

        return response()->json([
            'dry_run' => $dryRun,
            'errors' => $out['errors'],
            'preview' => $out['preview'],
            'created_ids' => $out['created_ids'],
        ]);
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
            'name', 'description', 'unit_price', 'is_active', 'quantity_available', 'tenant_company_id',
        ]);
        $before['inventory_reference'] = $inventoryLot->sku;
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
