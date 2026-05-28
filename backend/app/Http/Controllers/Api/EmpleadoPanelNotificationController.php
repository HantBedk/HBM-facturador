<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmpleadoNotificationResource;
use App\Models\PanelNotification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmpleadoPanelNotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->rol !== User::ROL_EMPLEADO) {
            return response()->json(['message' => 'Solo disponible para técnicos.'], 403);
        }

        $types = PanelNotification::empleadoNotificationTypes();

        $q = PanelNotification::query()
            ->where('user_id', $user->id)
            ->whereIn('type', $types)
            ->orderByDesc('created_at');

        if ($request->boolean('unread_only')) {
            $q->where('read', false);
        }

        if ($request->has('page')) {
            $perPage = min(50, max(5, $request->integer('per_page', 20)));

            return EmpleadoNotificationResource::collection($q->paginate($perPage));
        }

        $limit = min(100, max(1, $request->integer('limit', 40)));

        return EmpleadoNotificationResource::collection($q->limit($limit)->get());
    }

    public function unreadCount(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->rol !== User::ROL_EMPLEADO) {
            return response()->json(['message' => 'Solo disponible para técnicos.'], 403);
        }

        $types = PanelNotification::empleadoNotificationTypes();

        $n = PanelNotification::query()
            ->where('user_id', $user->id)
            ->whereIn('type', $types)
            ->where('read', false)
            ->count();

        return response()->json(['count' => $n]);
    }

    public function markRead(Request $request, PanelNotification $panel_notification): EmpleadoNotificationResource|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->rol !== User::ROL_EMPLEADO) {
            return response()->json(['message' => 'Solo disponible para técnicos.'], 403);
        }

        if ((int) $panel_notification->user_id !== (int) $user->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        if (! in_array($panel_notification->type, PanelNotification::empleadoNotificationTypes(), true)) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $panel_notification->read = true;
        $panel_notification->save();

        return new EmpleadoNotificationResource($panel_notification);
    }

    public function readAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->rol !== User::ROL_EMPLEADO) {
            return response()->json(['message' => 'Solo disponible para técnicos.'], 403);
        }

        $types = PanelNotification::empleadoNotificationTypes();

        PanelNotification::query()
            ->where('user_id', $user->id)
            ->whereIn('type', $types)
            ->where('read', false)
            ->update(['read' => true]);

        return response()->json(['ok' => true]);
    }

    /** Genera un ticket de un solo uso (TTL 60 s) para abrir el stream SSE. */
    public function streamTicket(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->rol !== User::ROL_EMPLEADO) {
            return response()->json(['message' => 'Solo disponible para técnicos.'], 403);
        }

        $ticket = (string) Str::uuid();
        Cache::put("notif_sse_emp_ticket.{$ticket}", $user->id, now()->addMinutes(1));

        return response()->json(['ticket' => $ticket]);
    }

    /**
     * Endpoint SSE para técnicos.
     * No usa middleware de auth; valida via ticket de un solo uso.
     */
    public function stream(Request $request): StreamedResponse
    {
        $ticket = (string) $request->query('ticket', '');
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $ticket)) {
            abort(401, 'Ticket inválido.');
        }

        $userId = Cache::pull("notif_sse_emp_ticket.{$ticket}");
        if (! $userId) {
            abort(401, 'Ticket inválido o expirado.');
        }

        $types = PanelNotification::empleadoNotificationTypes();

        return response()->stream(function () use ($userId, $types) {
            set_time_limit(70);
            if (ob_get_level() > 0) {
                ob_end_flush();
            }

            $lastCount = -1;
            $start = time();

            while (time() - $start < 55) {
                $count = PanelNotification::query()
                    ->where('user_id', $userId)
                    ->whereIn('type', $types)
                    ->where('read', false)
                    ->count();

                if ($count !== $lastCount) {
                    $lastCount = $count;
                    echo 'data: '.json_encode(['count' => $count])."\n\n";
                } else {
                    echo ": heartbeat\n\n";
                }

                ob_flush();
                flush();

                if (connection_aborted()) {
                    break;
                }

                sleep(8);
            }

            echo "event: close\ndata: {}\n\n";
            ob_flush();
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
