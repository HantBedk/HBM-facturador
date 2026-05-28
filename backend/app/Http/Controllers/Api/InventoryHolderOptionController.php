<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\InventoryInternalHolders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryHolderOptionController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->isAdminEquipo()) {
            abort(403);
        }

        $rows = InventoryInternalHolders::effectiveUsersOrdered()->map(fn ($u) => [
            'id' => $u->id,
            'nombre' => $u->nombre,
            'correo' => $u->correo,
            'rol' => $u->rol,
        ])->values()->all();

        return response()->json(['data' => $rows]);
    }
}
