<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventorySaleLine;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Totales por titular (dueño del lote) según líneas de venta en un rango de fechas.
 */
class InventoryOwnerAccrualController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! $request->user()->isAdminEquipo()) {
            abort(403);
        }

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'tenant_company_id' => ['nullable', 'integer', 'exists:companies,id'],
        ]);

        $q = InventorySaleLine::query()
            ->join('inventory_sales', 'inventory_sales.id', '=', 'inventory_sale_lines.inventory_sale_id')
            ->when($validated['from'] ?? null, fn ($q, $d) => $q->whereDate('inventory_sales.created_at', '>=', $d))
            ->when($validated['to'] ?? null, fn ($q, $d) => $q->whereDate('inventory_sales.created_at', '<=', $d))
            ->when(
                array_key_exists('tenant_company_id', $validated) && $validated['tenant_company_id'] !== null,
                fn ($q) => $q->where('inventory_sale_lines.tenant_company_id', (int) $validated['tenant_company_id'])
            )
            ->when(
                array_key_exists('owner_user_id', $validated) && $validated['owner_user_id'] !== null,
                fn ($q) => $q->where('inventory_sale_lines.owner_user_id', (int) $validated['owner_user_id'])
            );

        if (($validated['tenant_company_id'] ?? null) === null && $request->input('tenant_scope') === 'internal') {
            $q->whereNull('inventory_sale_lines.tenant_company_id');
        }

        $rows = $q->clone()
            ->select([
                'inventory_sale_lines.owner_user_id',
                DB::raw('SUM(inventory_sale_lines.line_total) as total_amount'),
                DB::raw('SUM(inventory_sale_lines.quantity) as units_sold'),
            ])
            ->groupBy('inventory_sale_lines.owner_user_id')
            ->get();

        $ownerIds = $rows->pluck('owner_user_id')->filter()->all();
        $users = User::query()
            ->whereIn('id', $ownerIds)
            ->get(['id', 'nombre', 'correo'])
            ->keyBy('id');

        $data = $rows->map(function ($row) use ($users) {
            $u = $users->get($row->owner_user_id);

            return [
                'owner_user_id' => (int) $row->owner_user_id,
                'owner' => $u ? [
                    'id' => $u->id,
                    'nombre' => $u->nombre,
                    'correo' => $u->correo,
                ] : null,
                'total_amount' => (string) $row->total_amount,
                'units_sold' => (int) $row->units_sold,
            ];
        })->values()->all();

        return response()->json(['data' => $data]);
    }
}
