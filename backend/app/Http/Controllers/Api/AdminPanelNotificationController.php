<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PanelNotificationResource;
use App\Models\PanelNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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

        $limit = min(100, max(1, $request->integer('limit', 40)));

        return PanelNotificationResource::collection($q->limit($limit)->get());
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $n = PanelNotification::query()
            ->where('user_id', $request->user()->id)
            ->where('read', false)
            ->count();

        return response()->json(['count' => $n]);
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
}
