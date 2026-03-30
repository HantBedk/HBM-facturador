<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
class UserController extends Controller
{
    public function empleadosActivos(): JsonResponse
    {
        $rows = User::query()
            ->where('rol', User::ROL_EMPLEADO)
            ->where('estado', User::ESTADO_ACTIVO)
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'correo']);

        return response()->json(['data' => $rows]);
    }
}
