<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesInventoryLotEmpleadoCustody;
use App\Http\Controllers\Controller;
use App\Models\InventoryAuditEvent;
use App\Models\InventoryLot;
use App\Models\InventoryLotAttachment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class InventoryLotAttachmentController extends Controller
{
    use AuthorizesInventoryLotEmpleadoCustody;

    private const MAX_ATTACHMENTS_PER_LOT = 30;

    public function index(Request $request, InventoryLot $inventoryLot): JsonResponse
    {
        $this->assertCanViewLot($request, $inventoryLot);

        $rows = InventoryLotAttachment::query()
            ->where('inventory_lot_id', $inventoryLot->id)
            ->with([
                'uploadedBy:id,nombre,correo',
                'auditEvent:id,action,occurred_at,entity_type,entity_id',
            ])
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $data = $rows->map(fn (InventoryLotAttachment $a) => $this->attachmentPayload($a))->values()->all();

        return response()->json(['data' => $data]);
    }

    public function store(Request $request, InventoryLot $inventoryLot): JsonResponse
    {
        $this->assertCanMutateAttachments($request, $inventoryLot);

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:jpeg,jpg,png,gif,webp,pdf'],
            'inventory_audit_event_id' => ['sometimes', 'nullable', 'integer', 'exists:inventory_audit_events,id'],
        ]);

        /** @var UploadedFile $file */
        $file = $validated['file'];
        $auditEventId = null;
        if (array_key_exists('inventory_audit_event_id', $validated)
            && $validated['inventory_audit_event_id'] !== null
            && $validated['inventory_audit_event_id'] !== '') {
            $auditEventId = (int) $validated['inventory_audit_event_id'];
        }
        if ($auditEventId !== null && $auditEventId > 0) {
            $audit = InventoryAuditEvent::query()->find($auditEventId);
            if (
                $audit === null
                || $audit->entity_type !== 'inventory_lot'
                || (int) $audit->entity_id !== (int) $inventoryLot->id
            ) {
                throw ValidationException::withMessages([
                    'inventory_audit_event_id' => ['El evento de auditoría no corresponde a este activo.'],
                ]);
            }
        }
        if (! $file->isValid()) {
            throw ValidationException::withMessages(['file' => ['El archivo no es válido.']]);
        }

        $count = InventoryLotAttachment::query()->where('inventory_lot_id', $inventoryLot->id)->count();
        if ($count >= self::MAX_ATTACHMENTS_PER_LOT) {
            throw ValidationException::withMessages([
                'file' => ['Se alcanzó el máximo de adjuntos por activo ('.self::MAX_ATTACHMENTS_PER_LOT.').'],
            ]);
        }

        $path = $file->store('inventory_lot_attachments/'.$inventoryLot->id, 'public');
        $attachment = InventoryLotAttachment::query()->create([
            'inventory_lot_id' => $inventoryLot->id,
            'original_filename' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize() ?: null,
            'uploaded_by_user_id' => $request->user()?->id,
            'inventory_audit_event_id' => ($auditEventId !== null && $auditEventId > 0) ? $auditEventId : null,
        ]);
        $attachment->load(['uploadedBy:id,nombre,correo', 'auditEvent:id,action,occurred_at']);

        $after = [
            'attachment_id' => $attachment->id,
            'original_filename' => $attachment->original_filename,
        ];
        if ($attachment->inventory_audit_event_id !== null) {
            $after['inventory_audit_event_id'] = $attachment->inventory_audit_event_id;
        }
        $this->audit($request, $inventoryLot, 'inventory_lot_attachment_added', null, $after);

        return response()->json(['data' => $this->attachmentPayload($attachment)], 201);
    }

    public function destroy(Request $request, InventoryLot $inventoryLot, InventoryLotAttachment $attachment): JsonResponse
    {
        if ((int) $attachment->inventory_lot_id !== (int) $inventoryLot->id) {
            abort(404);
        }

        $this->assertCanMutateAttachments($request, $inventoryLot);

        $before = [
            'attachment_id' => $attachment->id,
            'original_filename' => $attachment->original_filename,
        ];
        Storage::disk('public')->delete($attachment->path);
        $attachment->delete();

        $this->audit($request, $inventoryLot, 'inventory_lot_attachment_deleted', $before, null);

        return response()->json(['ok' => true]);
    }

    private function attachmentPayload(InventoryLotAttachment $a): array
    {
        $linked = null;
        if ($a->relationLoaded('auditEvent') && $a->auditEvent) {
            $linked = [
                'id' => $a->auditEvent->id,
                'action' => $a->auditEvent->action,
                'occurred_at' => $a->auditEvent->occurred_at?->toIso8601String(),
            ];
        }

        return [
            'id' => $a->id,
            'original_filename' => $a->original_filename,
            'url' => Storage::disk('public')->url($a->path),
            'mime_type' => $a->mime_type,
            'size_bytes' => $a->size_bytes,
            'inventory_audit_event_id' => $a->inventory_audit_event_id,
            'linked_audit_event' => $linked,
            'created_at' => $a->created_at?->toIso8601String(),
            'uploaded_by' => $a->relationLoaded('uploadedBy') && $a->uploadedBy
                ? ['id' => $a->uploadedBy->id, 'nombre' => $a->uploadedBy->nombre]
                : null,
        ];
    }

    private function assertCanViewLot(Request $request, InventoryLot $lot): void
    {
        $actor = $request->user();
        $tenantCompanyId = $this->tenantCompanyIdFromRequest($request);
        $this->assertLotTenantScope($lot, $tenantCompanyId);

        if (! $actor->isAdminEquipo()) {
            if (! $this->empleadoCustodyMaintenanceReadAllowed($request, $lot, $tenantCompanyId)) {
                if ((int) $lot->owner_user_id !== (int) $actor->id) {
                    abort(403);
                }
                if ($lot->lifecycle_status !== InventoryLot::LIFECYCLE_ACTIVO) {
                    abort(403);
                }
            }
        }
    }

    /**
     * Alta/baja de adjuntos: administración (titular o super) o técnico titular del lote en estado activo.
     */
    private function assertCanMutateAttachments(Request $request, InventoryLot $lot): void
    {
        $actor = $request->user();
        $tenantCompanyId = $this->tenantCompanyIdFromRequest($request);
        $this->assertLotTenantScope($lot, $tenantCompanyId);

        if ($actor->isAdminEquipo()) {
            $this->authorizeLotManagement($actor, $lot);

            return;
        }

        if ((int) $lot->owner_user_id !== (int) $actor->id) {
            abort(403);
        }
        if ($lot->lifecycle_status !== InventoryLot::LIFECYCLE_ACTIVO) {
            abort(403);
        }
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
