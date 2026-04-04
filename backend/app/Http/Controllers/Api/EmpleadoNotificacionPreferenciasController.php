<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PanelNotification;
use App\Models\User;
use App\Services\EmpleadoNotificacionSettings;
use App\Services\EmpleadoNotificacionUserPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmpleadoNotificacionPreferenciasController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->rol !== User::ROL_EMPLEADO) {
            return response()->json(['message' => 'Solo disponible para técnicos.'], 403);
        }

        return response()->json([
            'items' => app(EmpleadoNotificacionUserPreferences::class)->getItemsForUser($user),
            'help' => 'Solo puedes activar tipos que el administrador haya habilitado para el equipo. Si desactivas uno, no recibirás avisos nuevos de ese tipo.',
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->rol !== User::ROL_EMPLEADO) {
            return response()->json(['message' => 'Solo disponible para técnicos.'], 403);
        }

        $data = $request->validate([
            'types' => ['required', 'array'],
        ]);

        $admin = app(EmpleadoNotificacionSettings::class)->getMap();
        $merged = [];
        foreach ($data['types'] as $key => $val) {
            if (! is_string($key)) {
                throw ValidationException::withMessages([
                    'types' => ['Formato de tipos no válido.'],
                ]);
            }
            if (! in_array($key, PanelNotification::empleadoNotificationTypes(), true)) {
                throw ValidationException::withMessages([
                    'types' => ['Tipo no permitido: '.$key],
                ]);
            }
            if (! ($admin[$key] ?? true)) {
                throw ValidationException::withMessages([
                    'types' => ['No puedes modificar un tipo deshabilitado por administración: '.$key],
                ]);
            }
            $merged[$key] = (bool) $val;
        }

        app(EmpleadoNotificacionUserPreferences::class)->saveForUser($user, $merged);

        return response()->json([
            'message' => 'Preferencias guardadas.',
            'items' => app(EmpleadoNotificacionUserPreferences::class)->getItemsForUser($user->fresh()),
        ]);
    }
}
