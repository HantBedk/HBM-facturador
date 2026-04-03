<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PanelNotification;
use App\Models\User;
use App\Services\PanelNotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmpleadoCorreoSolicitudController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->rol !== User::ROL_EMPLEADO) {
            return response()->json(['message' => 'Solo disponible para técnicos.'], 403);
        }

        $data = $request->validate([
            'correo_solicitado' => [
                'required',
                'string',
                'email:filter',
                'max:255',
                Rule::unique('users', 'correo')->ignore($user->id),
            ],
        ]);

        $nuevo = trim($data['correo_solicitado']);
        if (strcasecmp($nuevo, $user->correo) === 0) {
            throw ValidationException::withMessages([
                'correo_solicitado' => ['El correo debe ser distinto al que usas actualmente para iniciar sesión.'],
            ]);
        }

        $user->correo_solicitado = $nuevo;
        $user->correo_solicitado_at = now();
        $user->save();

        app(PanelNotificationDispatcher::class)->notifyAdmins(
            PanelNotification::TYPE_EMAIL_CHANGE_REQUEST,
            'El técnico '.$user->nombre.' solicitó cambiar su correo a '.$nuevo.'. Revisa y aprueba desde Empleados.',
            [
                'link' => '/admin/empleados',
                'empleado_id' => $user->id,
                'correo_solicitado' => $nuevo,
            ],
        );

        return response()->json([
            'message' => 'Solicitud enviada. Un administrador revisará el correo sugerido antes de aplicarlo.',
            'correo_solicitado' => $user->correo_solicitado,
            'correo_solicitado_at' => $user->correo_solicitado_at?->toIso8601String(),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->rol !== User::ROL_EMPLEADO) {
            return response()->json(['message' => 'Solo disponible para técnicos.'], 403);
        }

        $user->correo_solicitado = null;
        $user->correo_solicitado_at = null;
        $user->save();

        return response()->json(['ok' => true]);
    }
}
