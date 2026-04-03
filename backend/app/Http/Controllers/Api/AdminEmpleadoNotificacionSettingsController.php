<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EmpleadoNotificacionSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminEmpleadoNotificacionSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        $svc = app(EmpleadoNotificacionSettings::class);

        return response()->json([
            'items' => $svc->getMapWithLabels(),
            'help' => 'Si desactivas un tipo, no se generarán avisos nuevos para los técnicos. Los ya enviados siguen visibles en su campana.',
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'types' => ['required', 'array'],
        ]);

        $svc = app(EmpleadoNotificacionSettings::class);
        $merged = $svc->getMap();
        foreach ($data['types'] as $key => $val) {
            if (! is_string($key) || ! array_key_exists($key, $merged)) {
                throw ValidationException::withMessages([
                    'types' => ['Clave de tipo no permitida: '.(is_string($key) ? $key : '')],
                ]);
            }
            $merged[$key] = (bool) $val;
        }
        $svc->saveMap($merged);

        return response()->json([
            'message' => 'Preferencias guardadas.',
            'items' => $svc->getMapWithLabels(),
        ]);
    }
}
