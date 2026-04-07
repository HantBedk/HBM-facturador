<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminActivityLogController extends Controller
{
    /**
     * Acciones del filtro «Solo facturas y pagos»: facturación, cobros a cliente y abonos de referencia a técnicos.
     */
    private const INVOICE_ACTIONS = [
        'factura_creada',
        'factura_editada',
        'factura_aprobada',
        'factura_enviada',
        'factura_eliminada',
        'factura_token_publico_regenerado',
        'factura_auto_borrador',
        'pago_registrado',
        'pago_eliminado',
        'servicio_pago_tecnico',
    ];

    /**
     * Movimientos agrupados por usuario (admin, super_admin, técnico).
     * Query: scope=all|facturas, limit (20–300, default 150).
     */
    public function index(Request $request): JsonResponse
    {
        $scope = $request->string('scope')->toString();
        if (! in_array($scope, ['all', 'facturas'], true)) {
            $scope = 'all';
        }

        $limit = min(300, max(20, (int) $request->query('limit', 150)));

        $q = ActivityLog::query()
            ->with(['user:id,nombre,correo,rol'])
            ->orderByDesc('created_at');

        if ($scope === 'facturas') {
            $q->whereIn('action', self::INVOICE_ACTIONS);
        }

        $logs = $q->limit($limit)->get();

        $actor = $request->user();
        if ($actor !== null && $actor->rol === User::ROL_ADMIN) {
            $logs = $logs->filter(function (ActivityLog $l) use ($actor) {
                $u = $l->user;
                if ($u === null) {
                    return true;
                }
                if (! $u->isAdminEquipo()) {
                    return true;
                }

                return $u->id === $actor->id;
            })->values();
        }

        $groups = $logs->groupBy(fn (ActivityLog $l) => (string) ($l->user_id ?? '0'));

        $out = [];
        foreach ($groups as $items) {
            /** @var \Illuminate\Support\Collection<int, ActivityLog> $items */
            $items = $items->sortByDesc('created_at')->values();
            $first = $items->first();
            $u = $first->user;
            $out[] = [
                'user' => $u === null
                    ? null
                    : [
                        'id' => $u->id,
                        'nombre' => $u->nombre,
                        'correo' => $u->correo,
                        'rol' => $u->rol,
                    ],
                'movimientos' => $items->map(fn (ActivityLog $l) => [
                    'id' => $l->id,
                    'action' => $l->action,
                    'description' => $l->description,
                    'created_at' => $l->created_at?->toIso8601String(),
                ])->values()->all(),
            ];
        }

        usort($out, function (array $a, array $b): int {
            $ta = $a['movimientos'][0]['created_at'] ?? '';
            $tb = $b['movimientos'][0]['created_at'] ?? '';

            return strcmp((string) $tb, (string) $ta);
        });

        return response()->json([
            'scope' => $scope,
            'limit' => $limit,
            'groups' => array_values($out),
        ]);
    }
}
