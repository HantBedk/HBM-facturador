<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\PanelNotification;
use App\Models\User;
use App\Services\PanelNotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class AdminCorreoSolicitudController extends Controller
{
    public function approve(User $user): UserResource|JsonResponse
    {
        if ($user->rol !== User::ROL_EMPLEADO) {
            return response()->json(['message' => 'Solo aplica a cuentas de técnico.'], 422);
        }

        if ($user->correo_solicitado === null || $user->correo_solicitado === '') {
            return response()->json(['message' => 'No hay solicitud de correo pendiente para este usuario.'], 422);
        }

        $nuevo = trim($user->correo_solicitado);
        if (User::query()->where('correo', $nuevo)->where('id', '!=', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'correo' => ['Ese correo ya está en uso por otra cuenta. Rechace la solicitud o pida otro correo al técnico.'],
            ]);
        }

        $user->correo = $nuevo;
        $user->correo_solicitado = null;
        $user->correo_solicitado_at = null;
        $user->save();

        app(PanelNotificationDispatcher::class)->notifyUser(
            (int) $user->id,
            PanelNotification::TYPE_EMP_CORREO_APROBADO,
            'Tu solicitud de cambio de correo fue aprobada. Usa '.$nuevo.' para iniciar sesión.',
            ['link' => '/empleado/perfil', 'nuevo_correo' => $nuevo],
        );

        return new UserResource($user->fresh());
    }

    public function reject(User $user): UserResource|JsonResponse
    {
        if ($user->rol !== User::ROL_EMPLEADO) {
            return response()->json(['message' => 'Solo aplica a cuentas de técnico.'], 422);
        }

        if ($user->correo_solicitado === null || $user->correo_solicitado === '') {
            return response()->json(['message' => 'No hay solicitud de correo pendiente para este usuario.'], 422);
        }

        $solicitado = trim($user->correo_solicitado);
        $user->correo_solicitado = null;
        $user->correo_solicitado_at = null;
        $user->save();

        app(PanelNotificationDispatcher::class)->notifyUser(
            (int) $user->id,
            PanelNotification::TYPE_EMP_CORREO_RECHAZADO,
            'Tu solicitud de cambio de correo ('.$solicitado.') no fue aprobada. Sigue usando tu correo actual para iniciar sesión.',
            ['link' => '/empleado/perfil'],
        );

        return new UserResource($user->fresh());
    }
}
