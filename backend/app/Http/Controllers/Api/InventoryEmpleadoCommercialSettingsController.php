<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryEmpleadoCommercialSettingsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->rol !== User::ROL_EMPLEADO) {
            return response()->json(['message' => 'Solo disponible para técnicos.'], 403);
        }

        return response()->json([
            'data' => [
                'venta_enabled' => AppSetting::getBool(AppSetting::KEY_EMPLEADO_INVENTORY_VENTA_ENABLED, false),
                'alquiler_enabled' => AppSetting::getBool(AppSetting::KEY_EMPLEADO_INVENTORY_ALQUILER_ENABLED, false),
            ],
        ]);
    }
}
