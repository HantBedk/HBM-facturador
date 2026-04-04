<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PanelNotification;
use App\Models\User;
use App\Services\PanelNotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminEmpleadoPerfilController extends Controller
{
    public function show(Request $request, User $user): JsonResponse
    {
        if ($user->rol !== User::ROL_EMPLEADO) {
            return response()->json(['message' => 'Solo aplica a cuentas de técnico.'], 422);
        }

        return response()->json(array_merge(
            EmpleadoPerfilController::catalogPayload(),
            [
                'profile' => EmpleadoPerfilController::profileFromUser($user),
                'user' => [
                    'id' => $user->id,
                    'nombre' => $user->nombre,
                    'correo' => $user->correo,
                    'estado' => $user->estado,
                    'correo_solicitado' => $user->correo_solicitado,
                    'correo_solicitado_at' => $user->correo_solicitado_at?->toIso8601String(),
                ],
            ]
        ));
    }

    public function clearDatosPago(Request $request, User $user): JsonResponse
    {
        if ($user->rol !== User::ROL_EMPLEADO) {
            return response()->json(['message' => 'Solo aplica a cuentas de técnico.'], 422);
        }

        $user->banco_codigo = null;
        $user->cuenta_tipo = null;
        $user->cuenta_numero = null;
        $user->perfil_completado_at = null;
        $user->save();

        app(PanelNotificationDispatcher::class)->notifyUser(
            (int) $user->id,
            PanelNotification::TYPE_EMP_DATOS_PAGO_REQUIEREN_ACTUALIZACION,
            'Los datos de pago de tu perfil fueron borrados por un administrador (no pudieron usarse para abonarte). Actualiza banco y cuenta o llave Bre-B en «Mi perfil».',
            ['link' => '/empleado/perfil'],
        );

        return response()->json([
            'message' => 'Datos de pago borrados. El técnico recibió un aviso para actualizar su información.',
            'profile' => EmpleadoPerfilController::profileFromUser($user->fresh()),
        ]);
    }
}
