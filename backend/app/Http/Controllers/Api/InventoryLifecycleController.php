<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryAuditEvent;
use App\Models\InventoryLifecycleRequestApproval;
use App\Models\InventoryLifecycleTransitionRequest;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\PanelNotification;
use App\Models\User;
use App\Services\PanelNotificationDispatcher;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryLifecycleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->isAdminEquipo()) {
            abort(403);
        }

        $q = InventoryLifecycleTransitionRequest::query()
            ->with([
                'lot:id,name,sku,description,serial_number,lifecycle_status,tenant_company_id',
                'requestedBy:id,nombre,correo,rol',
                'resolvedBy:id,nombre,correo,rol',
                'approvals.approvedBy:id,nombre,correo,rol',
            ])
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $q->where('status', (string) $request->input('status'));
        }
        if ($request->filled('target_status')) {
            $q->where('target_status', (string) $request->input('target_status'));
        }
        if ($request->filled('inventory_lot_id')) {
            $q->where('inventory_lot_id', $request->integer('inventory_lot_id'));
        }

        return response()->json($q->paginate(Pagination::perPage($request))->withQueryString());
    }

    public function report(Request $request, InventoryLot $inventoryLot): JsonResponse
    {
        $actor = $request->user();
        $targetInput = (string) $request->input('target_status');
        $rules = [
            'target_status' => ['required', 'string', 'in:activo,reparacion,baja'],
            'reason' => ['required', 'string', 'max:3000'],
            'resolution_note' => ['nullable', 'string', 'max:3000'],
        ];
        if (in_array($targetInput, [InventoryLot::LIFECYCLE_REPARACION, InventoryLot::LIFECYCLE_ACTIVO], true)) {
            $rules['physical_condition'] = ['required', 'string', 'max:120'];
            $rules['repair_damage_kind'] = ['required', 'string', 'in:hardware,software'];
        } else {
            $rules['physical_condition'] = ['sometimes', 'nullable', 'string', 'max:120'];
            $rules['repair_damage_kind'] = ['sometimes', 'nullable', 'string', 'in:hardware,software'];
        }
        $validated = $request->validate($rules);
        // Reactivación: motivo genérico en `reason` y detalle operativo en `resolution_note` (un solo cuadro en UI).
        if (($validated['target_status'] ?? '') === InventoryLot::LIFECYCLE_ACTIVO) {
            $detail = trim((string) ($validated['resolution_note'] ?? ''));
            if ($detail === '') {
                throw ValidationException::withMessages([
                    'resolution_note' => ['Indique el detalle de la reparación realizada.'],
                ]);
            }
        }

        if (! $this->canOperateLot($actor, $inventoryLot)) {
            abort(403);
        }
        if ($inventoryLot->lifecycle_status === InventoryLot::LIFECYCLE_BAJA) {
            throw ValidationException::withMessages([
                'target_status' => ['El equipo ya está dado de baja permanente.'],
            ]);
        }

        $target = (string) $validated['target_status'];
        if ($target === InventoryLot::LIFECYCLE_BAJA) {
            $payload = DB::transaction(function () use ($actor, $inventoryLot, $validated, $request) {
                $pending = InventoryLifecycleTransitionRequest::query()
                    ->where('inventory_lot_id', $inventoryLot->id)
                    ->where('status', InventoryLifecycleTransitionRequest::STATUS_PENDING)
                    ->where('target_status', InventoryLifecycleTransitionRequest::TARGET_BAJA)
                    ->first();
                if ($pending) {
                    throw ValidationException::withMessages([
                        'target_status' => ['Ya existe una solicitud de baja pendiente para este equipo.'],
                    ]);
                }

                $transition = InventoryLifecycleTransitionRequest::query()->create([
                    'inventory_lot_id' => $inventoryLot->id,
                    'tenant_company_id' => $inventoryLot->tenant_company_id,
                    'requested_by_user_id' => $actor->id,
                    'target_status' => InventoryLifecycleTransitionRequest::TARGET_BAJA,
                    'status' => InventoryLifecycleTransitionRequest::STATUS_PENDING,
                    'reason' => trim((string) $validated['reason']),
                    'required_approvals' => 2,
                ]);

                if ($actor->isAdminEquipo()) {
                    InventoryLifecycleRequestApproval::query()->create([
                        'request_id' => $transition->id,
                        'approved_by_user_id' => $actor->id,
                        'decision' => InventoryLifecycleRequestApproval::DECISION_APPROVED,
                        'note' => 'Autoaprobación del solicitante administrativo.',
                        'created_at' => now(),
                    ]);
                }

                $this->audit($request, $inventoryLot, 'lifecycle_baja_requested', null, [
                    'request_id' => $transition->id,
                    'reason' => $transition->reason,
                    'required_approvals' => $transition->required_approvals,
                ]);

                return $transition->fresh(['approvals.approvedBy:id,nombre,correo,rol']);
            });

            app(PanelNotificationDispatcher::class)->notifyAdmins(
                PanelNotification::TYPE_INVENTORY_ACTIVITY,
                $actor->nombre.' registró solicitud de baja para: '.$inventoryLot->name.'.',
                ['link' => '/admin/inventario', 'inventory_lot_id' => $inventoryLot->id, 'request_id' => $payload->id]
            );

            return response()->json([
                'ok' => true,
                'mode' => 'approval_required',
                'request' => $payload,
            ], 201);
        }

        $updated = DB::transaction(function () use ($actor, $inventoryLot, $validated, $request, $target) {
            $before = $inventoryLot->only([
                'lifecycle_status', 'is_active', 'allow_sale', 'allow_rental', 'repair_reason', 'repair_resolution',
                'physical_condition', 'repair_damage_kind',
            ]);

            if ($target === InventoryLot::LIFECYCLE_REPARACION) {
                $inventoryLot->lifecycle_status = InventoryLot::LIFECYCLE_REPARACION;
                $inventoryLot->lifecycle_status_changed_at = now();
                $inventoryLot->repair_reason = trim((string) $validated['reason']);
                $inventoryLot->repair_resolution = null;
                $inventoryLot->physical_condition = trim((string) $validated['physical_condition']);
                $inventoryLot->repair_damage_kind = (string) $validated['repair_damage_kind'];
                $inventoryLot->is_active = false;
                $inventoryLot->allow_sale = false;
                $inventoryLot->allow_rental = false;
                $movementType = InventoryMovement::TYPE_REPARACION;
                $action = 'lifecycle_to_reparacion';
            } else {
                if ($inventoryLot->lifecycle_status !== InventoryLot::LIFECYCLE_REPARACION) {
                    throw ValidationException::withMessages([
                        'target_status' => ['Solo se puede activar un equipo que esté en reparación.'],
                    ]);
                }
                $inventoryLot->lifecycle_status = InventoryLot::LIFECYCLE_ACTIVO;
                $inventoryLot->lifecycle_status_changed_at = now();
                $inventoryLot->repair_resolution = trim((string) ($validated['resolution_note'] ?? $validated['reason']));
                $inventoryLot->physical_condition = trim((string) $validated['physical_condition']);
                $inventoryLot->repair_damage_kind = (string) $validated['repair_damage_kind'];
                $inventoryLot->is_active = true;
                $movementType = InventoryMovement::TYPE_REACTIVACION;
                $action = 'lifecycle_to_activo';
            }
            $inventoryLot->save();

            $movementNote = trim((string) $validated['reason']);
            if ($target === InventoryLot::LIFECYCLE_ACTIVO) {
                $movementNote = trim((string) ($validated['resolution_note'] ?? $movementNote));
            }
            if (in_array($target, [InventoryLot::LIFECYCLE_REPARACION, InventoryLot::LIFECYCLE_ACTIVO], true)) {
                $pk = (string) $validated['repair_damage_kind'];
                $damageLabel = $pk === InventoryLot::REPAIR_DAMAGE_HARDWARE ? 'Hardware' : 'Software';
                $phys = trim((string) $validated['physical_condition']);
                $movementNote = trim($movementNote.' [Estado físico: '.$phys.'; daño: '.$damageLabel.']');
            }

            InventoryMovement::query()->create([
                'inventory_lot_id' => $inventoryLot->id,
                'user_id' => $actor->id,
                'tenant_company_id' => $inventoryLot->tenant_company_id,
                'type' => $movementType,
                'quantity_delta' => 0,
                'inventory_sale_line_id' => null,
                'note' => $movementNote,
                'created_at' => now(),
            ]);

            $after = $inventoryLot->only([
                'lifecycle_status', 'is_active', 'allow_sale', 'allow_rental', 'repair_reason', 'repair_resolution',
                'physical_condition', 'repair_damage_kind',
            ]);
            $this->audit($request, $inventoryLot, $action, $before, $after);

            return $inventoryLot->fresh();
        });

        app(PanelNotificationDispatcher::class)->notifyAdmins(
            PanelNotification::TYPE_INVENTORY_ACTIVITY,
            $actor->nombre.' cambió estado de '.$inventoryLot->name.' a '.$updated->lifecycle_status.'.',
            ['link' => '/admin/inventario', 'inventory_lot_id' => $inventoryLot->id]
        );

        return response()->json([
            'ok' => true,
            'mode' => 'direct',
            'lot' => $updated,
        ]);
    }

    public function approve(Request $request, InventoryLifecycleTransitionRequest $transitionRequest): JsonResponse
    {
        $actor = $request->user();
        if (! $actor->isAdminEquipo()) {
            abort(403);
        }
        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:approved,rejected'],
            'note' => ['nullable', 'string', 'max:3000'],
        ]);

        $payload = DB::transaction(function () use ($actor, $transitionRequest, $validated, $request) {
            $req = InventoryLifecycleTransitionRequest::query()
                ->lockForUpdate()
                ->with(['lot'])
                ->findOrFail($transitionRequest->id);

            if ($req->status !== InventoryLifecycleTransitionRequest::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'status' => ['La solicitud ya fue resuelta.'],
                ]);
            }
            if ($req->target_status !== InventoryLifecycleTransitionRequest::TARGET_BAJA) {
                throw ValidationException::withMessages([
                    'target_status' => ['Solo se admite aprobación para solicitudes de baja.'],
                ]);
            }

            $existing = InventoryLifecycleRequestApproval::query()
                ->where('request_id', $req->id)
                ->where('approved_by_user_id', $actor->id)
                ->exists();
            if ($existing) {
                throw ValidationException::withMessages([
                    'decision' => ['Este usuario ya registró decisión sobre la solicitud.'],
                ]);
            }

            $decision = (string) $validated['decision'];
            InventoryLifecycleRequestApproval::query()->create([
                'request_id' => $req->id,
                'approved_by_user_id' => $actor->id,
                'decision' => $decision,
                'note' => $validated['note'] ?? null,
                'created_at' => now(),
            ]);

            if ($decision === InventoryLifecycleRequestApproval::DECISION_REJECTED) {
                $req->status = InventoryLifecycleTransitionRequest::STATUS_REJECTED;
                $req->resolved_at = now();
                $req->resolved_by_user_id = $actor->id;
                $req->resolution_note = $validated['note'] ?? 'Solicitud rechazada.';
                $req->save();

                $this->audit($request, $req->lot, 'lifecycle_baja_rejected', null, [
                    'request_id' => $req->id,
                    'resolved_by_user_id' => $actor->id,
                    'resolution_note' => $req->resolution_note,
                ]);

                return $req->fresh(['lot', 'approvals.approvedBy:id,nombre,correo,rol']);
            }

            $approvedCount = InventoryLifecycleRequestApproval::query()
                ->where('request_id', $req->id)
                ->where('decision', InventoryLifecycleRequestApproval::DECISION_APPROVED)
                ->count();

            if ($approvedCount >= (int) $req->required_approvals) {
                $lot = $req->lot;
                if ($lot->lifecycle_status === InventoryLot::LIFECYCLE_BAJA) {
                    throw ValidationException::withMessages([
                        'target_status' => ['El equipo ya fue dado de baja.'],
                    ]);
                }

                $before = $lot->only([
                    'lifecycle_status', 'is_active', 'allow_sale', 'allow_rental', 'decommission_reason', 'decommissioned_at',
                ]);
                $lot->lifecycle_status = InventoryLot::LIFECYCLE_BAJA;
                $lot->lifecycle_status_changed_at = now();
                $lot->decommission_reason = $req->reason;
                $lot->decommissioned_at = now();
                $lot->decommissioned_by_user_id = $actor->id;
                $lot->is_active = false;
                $lot->allow_sale = false;
                $lot->allow_rental = false;
                $lot->save();

                InventoryMovement::query()->create([
                    'inventory_lot_id' => $lot->id,
                    'user_id' => $actor->id,
                    'tenant_company_id' => $lot->tenant_company_id,
                    'type' => InventoryMovement::TYPE_BAJA,
                    'quantity_delta' => 0,
                    'inventory_sale_line_id' => null,
                    'note' => $req->reason,
                    'created_at' => now(),
                ]);

                $req->status = InventoryLifecycleTransitionRequest::STATUS_APPROVED;
                $req->resolved_at = now();
                $req->resolved_by_user_id = $actor->id;
                $req->resolution_note = $validated['note'] ?? 'Baja aprobada.';
                $req->save();

                $this->audit($request, $lot, 'lifecycle_baja_approved', $before, [
                    'lifecycle_status' => $lot->lifecycle_status,
                    'is_active' => $lot->is_active,
                    'allow_sale' => $lot->allow_sale,
                    'allow_rental' => $lot->allow_rental,
                    'decommission_reason' => $lot->decommission_reason,
                    'decommissioned_at' => $lot->decommissioned_at?->toIso8601String(),
                ]);
            } else {
                $this->audit($request, $req->lot, 'lifecycle_baja_approval_registered', null, [
                    'request_id' => $req->id,
                    'approved_count' => $approvedCount,
                    'required_approvals' => (int) $req->required_approvals,
                ]);
            }

            return $req->fresh(['lot', 'approvals.approvedBy:id,nombre,correo,rol']);
        });

        app(PanelNotificationDispatcher::class)->notifyAdmins(
            PanelNotification::TYPE_INVENTORY_ACTIVITY,
            $actor->nombre.' registró decisión de baja para '.$payload->lot?->name.'.',
            ['link' => '/admin/inventario', 'inventory_lot_id' => $payload->inventory_lot_id, 'request_id' => $payload->id]
        );

        return response()->json(['ok' => true, 'request' => $payload]);
    }

    private function canOperateLot(User $actor, InventoryLot $lot): bool
    {
        if ($actor->isAdminEquipo()) {
            return true;
        }

        return (int) $lot->owner_user_id === (int) $actor->id;
    }

    private function audit(Request $request, InventoryLot $lot, string $action, ?array $before, ?array $after): void
    {
        InventoryAuditEvent::query()->create([
            'tenant_company_id' => $lot->tenant_company_id,
            'entity_type' => 'inventory_lot',
            'entity_id' => $lot->id,
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

