<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryAuditEvent;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryAuditEventController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! $request->user()->isAdminEquipo()) {
            abort(403);
        }

        $validated = $request->validate([
            'tenant_company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'entity_type' => ['nullable', 'string', 'max:64'],
            'entity_id' => ['nullable', 'integer', 'min:1'],
            'action' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $q = InventoryAuditEvent::query()
            ->with(['actor:id,nombre,correo', 'tenantCompany:id,nombre,nit'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->when($validated['tenant_company_id'] ?? null, fn ($q, $v) => $q->where('tenant_company_id', (int) $v))
            ->when($validated['entity_type'] ?? null, fn ($q, $v) => $q->where('entity_type', $v))
            ->when($validated['entity_id'] ?? null, fn ($q, $v) => $q->where('entity_id', (int) $v))
            ->when($validated['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($validated['from'] ?? null, fn ($q, $v) => $q->whereDate('occurred_at', '>=', $v))
            ->when($validated['to'] ?? null, fn ($q, $v) => $q->whereDate('occurred_at', '<=', $v));

        if (($validated['tenant_company_id'] ?? null) === null && $request->input('tenant_scope') === 'internal') {
            $q->whereNull('tenant_company_id');
        }

        return response()->json($q->paginate(Pagination::perPage($request))->withQueryString());
    }
}
