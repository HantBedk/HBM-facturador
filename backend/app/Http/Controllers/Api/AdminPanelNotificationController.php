<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PanelNotificationResource;
use App\Models\PanelNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminPanelNotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = PanelNotification::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at');

        if ($request->boolean('unread_only')) {
            $q->where('read', false);
        }

        $cat = $request->string('category')->toString();
        if ($cat !== '' && in_array($cat, ['empleados', 'servicios', 'facturas'], true)) {
            $types = PanelNotification::typesInCategory($cat);
            if ($types !== []) {
                $q->whereIn('type', $types);
            }
        }

        // Con ?page=N devuelve paginación completa (para la página de historial).
        // Sin page devuelve colección plana (para el dropdown de la campana).
        if ($request->has('page')) {
            $perPage = min(50, max(5, $request->integer('per_page', 20)));

            return PanelNotificationResource::collection($q->paginate($perPage));
        }

        $limit = min(100, max(1, $request->integer('limit', 40)));

        return PanelNotificationResource::collection($q->limit($limit)->get());
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $base = fn () => PanelNotification::query()
            ->where('user_id', $userId)
            ->where('read', false);

        return response()->json([
            'count' => $base()->count(),
            'by_category' => [
                'empleados' => $base()->whereIn('type', PanelNotification::typesInCategory('empleados'))->count(),
                'servicios' => $base()->whereIn('type', PanelNotification::typesInCategory('servicios'))->count(),
                'facturas' => $base()->whereIn('type', PanelNotification::typesInCategory('facturas'))->count(),
            ],
        ]);
    }

    public function markRead(Request $request, PanelNotification $panel_notification): PanelNotificationResource|JsonResponse
    {
        if ((int) $panel_notification->user_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $panel_notification->read = true;
        $panel_notification->save();

        return new PanelNotificationResource($panel_notification);
    }

    public function readAll(Request $request): JsonResponse
    {
        PanelNotification::query()
            ->where('user_id', $request->user()->id)
            ->where('read', false)
            ->update(['read' => true]);

        return response()->json(['ok' => true]);
    }

    /** Genera un ticket de un solo uso (TTL 60 s) para abrir el stream SSE. */
    public function streamTicket(Request $request): JsonResponse
    {
        $ticket = (string) Str::uuid();
        Cache::put("notif_sse_ticket.{$ticket}", $request->user()->id, now()->addMinutes(1));

        return response()->json(['ticket' => $ticket]);
    }

    /**
     * Endpoint SSE: emite { count } cada vez que cambia el número de no-leídas.
     * No usa middleware de auth; valida via ticket de un solo uso.
     * Envía `event: close` al finalizar para que el cliente reconecte.
     */
    public function stream(Request $request): StreamedResponse
    {
        $ticket = (string) $request->query('ticket', '');
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $ticket)) {
            abort(401, 'Ticket inválido.');
        }

        $userId = Cache::pull("notif_sse_ticket.{$ticket}");
        if (! $userId) {
            abort(401, 'Ticket inválido o expirado.');
        }

        return response()->stream(function () use ($userId) {
            set_time_limit(70);
            if (ob_get_level() > 0) {
                ob_end_flush();
            }

            $lastCount = -1;
            $start = time();

            while (time() - $start < 55) {
                $count = PanelNotification::query()
                    ->where('user_id', $userId)
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
