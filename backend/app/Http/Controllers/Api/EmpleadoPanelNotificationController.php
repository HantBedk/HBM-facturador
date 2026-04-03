<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmpleadoNotificationResource;
use App\Models\PanelNotification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
}
