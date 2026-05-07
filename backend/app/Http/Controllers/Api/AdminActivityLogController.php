<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminActivityLogController extends Controller
{
    private static function activityLogVisibleTo(?User $actor, ActivityLog $l): bool
    {
        if ($actor === null || $actor->rol !== User::ROL_ADMIN) {
            return true;
        }
        $u = $l->user;
        if ($u === null) {
            return true;
        }
        if (! $u->isAdminEquipo()) {
            return true;
        }

        return $u->id === $actor->id;
    }

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
     * Query: scope=all|facturas, date=Y-m-d (día calendario en APP_TIMEZONE; omiso = hoy).
     * Opcional: calendar_month=Y-m para devolver `calendar.dates_with_activity` de ese mes (mismo scope y visibilidad que el listado).
     * Tope interno de filas por petición para un día (evita respuestas enormes).
     */
    public function index(Request $request): JsonResponse
    {
        $scope = $request->string('scope')->toString();
        if (! in_array($scope, ['all', 'facturas'], true)) {
            $scope = 'all';
        }

        $tz = (string) config('app.timezone');
        $dateRaw = $request->query('date');
        $dateRaw = is_string($dateRaw) ? trim($dateRaw) : '';
        try {
            $day = ($dateRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw) === 1)
                ? Carbon::createFromFormat('Y-m-d', $dateRaw, $tz)->startOfDay()
                : now($tz)->startOfDay();
        } catch (\Throwable) {
            $day = now($tz)->startOfDay();
        }

        $startUtc = $day->copy()->utc();
        $endUtc = $day->copy()->endOfDay()->utc();

        $limit = min(3000, max(100, (int) $request->query('limit', 2500)));

        $q = ActivityLog::query()
            ->with(['user:id,nombre,correo,rol'])
            ->whereBetween('created_at', [$startUtc, $endUtc])
            ->orderByDesc('created_at');

        if ($scope === 'facturas') {
            $q->whereIn('action', self::INVOICE_ACTIONS);
        }

        $logs = $q->limit($limit)->get();

        $actor = $request->user();
        if ($actor !== null && $actor->rol === User::ROL_ADMIN) {
            $logs = $logs->filter(fn (ActivityLog $l) => self::activityLogVisibleTo($actor, $l))->values();
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

        $calMonthRaw = $request->query('calendar_month');
        $calMonthRaw = is_string($calMonthRaw) ? trim($calMonthRaw) : '';
        $calMonthStart = $day->copy()->startOfMonth();
        if ($calMonthRaw !== '' && preg_match('/^\d{4}-\d{2}$/', $calMonthRaw) === 1) {
            try {
                $calMonthStart = Carbon::createFromFormat('Y-m', $calMonthRaw, $tz)->startOfMonth();
            } catch (\Throwable) {
                $calMonthStart = $day->copy()->startOfMonth();
            }
        }
        $calMonthEnd = $calMonthStart->copy()->endOfMonth();
        $calStartUtc = $calMonthStart->copy()->startOfDay()->utc();
        $calEndUtc = $calMonthEnd->copy()->endOfDay()->utc();

        $calQ = ActivityLog::query()
            ->with(['user:id,nombre,correo,rol'])
            ->whereBetween('created_at', [$calStartUtc, $calEndUtc]);

        if ($scope === 'facturas') {
            $calQ->whereIn('action', self::INVOICE_ACTIONS);
        }

        $calLogs = $calQ->get(['id', 'user_id', 'created_at']);
        if ($actor !== null && $actor->rol === User::ROL_ADMIN) {
            $calLogs = $calLogs->filter(fn (ActivityLog $l) => self::activityLogVisibleTo($actor, $l))->values();
        }

        $datesWithActivity = [];
        foreach ($calLogs as $log) {
            $d = $log->created_at?->timezone($tz)->toDateString();
            if ($d !== null && $d !== '') {
                $datesWithActivity[$d] = true;
            }
        }
        $datesWithActivityList = array_keys($datesWithActivity);
        sort($datesWithActivityList);

        $todayStr = now($tz)->toDateString();

        return response()->json([
            'scope' => $scope,
            'date' => $day->toDateString(),
            'timezone' => $tz,
            'limit' => $limit,
            'groups' => array_values($out),
            'calendar' => [
                'month' => $calMonthStart->format('Y-m'),
                'today' => $todayStr,
                'dates_with_activity' => $datesWithActivityList,
            ],
        ]);
    }
}
