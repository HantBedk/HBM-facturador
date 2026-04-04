<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class EmpleadoPasswordController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->rol !== User::ROL_EMPLEADO) {
            return response()->json(['message' => 'Solo disponible para técnicos.'], 403);
        }

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['La contraseña actual no es correcta.'],
            ]);
        }

        $user->password = $data['password'];
        $user->save();

        return response()->json(['message' => 'Contraseña actualizada correctamente.']);
    }
}
