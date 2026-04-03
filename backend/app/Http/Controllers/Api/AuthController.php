<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            // email:filter (PHP) acepta dominios de desarrollo tipo @*.local; la regla `email` sola usa RFC estricto.
            'correo' => ['required', 'string', 'email:filter', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ]);

        $user = User::where('correo', $data['correo'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'correo' => ['Correo o contraseña incorrectos.'],
            ]);
        }

        if ($user->estado !== User::ESTADO_ACTIVO) {
            return response()->json([
                'message' => 'Usuario inactivo. Contacte al administrador.',
            ], 403);
        }

        $device = $data['device_name'] ?? 'web';
        $expiresAt = now()->addDays(7);
        $token = $user->createToken($device, ['*'], $expiresAt)->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->userPayload($request->user()));
    }

    private function userPayload(User $user): array
    {
        $base = [
            'id' => $user->id,
            'nombre' => $user->nombre,
            'correo' => $user->correo,
            'rol' => $user->rol,
            'estado' => $user->estado,
        ];

        if ($user->rol === User::ROL_EMPLEADO) {
            $base['perfil_completado_at'] = $user->perfil_completado_at?->toIso8601String();
            $base['correo_solicitado'] = $user->correo_solicitado;
            $base['correo_solicitado_at'] = $user->correo_solicitado_at?->toIso8601String();
            $base['telefono'] = $user->telefono;
            $base['tipo_documento'] = $user->tipo_documento;
            $base['numero_documento'] = $user->numero_documento;
            $base['ciudad'] = $user->ciudad;
            $base['departamento'] = $user->departamento;
            $base['banco_codigo'] = $user->banco_codigo;
            $base['cuenta_tipo'] = $user->cuenta_tipo;
            $base['cuenta_numero'] = $user->cuenta_numero;
        }

        return $base;
    }
}
