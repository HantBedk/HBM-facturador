<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminUserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = User::query()->orderBy('nombre');

        if ($request->filled('q')) {
            $raw = $request->string('q')->toString();
            $term = '%'.addcslashes($raw, '%_\\').'%';
            $q->where(function ($w) use ($term) {
                $w->where('nombre', 'like', $term)
                    ->orWhere('correo', 'like', $term);
            });
        }

        if ($request->filled('rol') && in_array($request->string('rol')->toString(), [
            User::ROL_ADMIN,
            User::ROL_SUPER_ADMIN,
            User::ROL_EMPLEADO,
        ], true)) {
            $q->where('rol', $request->string('rol')->toString());
        }

        if ($request->filled('estado') && in_array($request->string('estado')->toString(), [
            User::ESTADO_ACTIVO,
            User::ESTADO_INACTIVO,
        ], true)) {
            $q->where('estado', $request->string('estado')->toString());
        }

        return UserResource::collection(
            $q->paginate(Pagination::perPage($request))->withQueryString()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'correo' => ['required', 'string', 'email:filter', 'max:255', 'unique:users,correo'],
            'password' => ['required', 'string', 'min:8'],
            'rol' => ['required', Rule::in([User::ROL_ADMIN, User::ROL_SUPER_ADMIN, User::ROL_EMPLEADO])],
            'estado' => ['required', Rule::in([User::ESTADO_ACTIVO, User::ESTADO_INACTIVO])],
        ]);

        $user = User::query()->create([
            'nombre' => trim($data['nombre']),
            'correo' => trim($data['correo']),
            'password' => $data['password'],
            'rol' => $data['rol'],
            'estado' => $data['estado'],
        ]);

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function update(Request $request, User $user): UserResource
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'correo' => ['required', 'string', 'email:filter', 'max:255', Rule::unique('users', 'correo')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8'],
            'rol' => ['required', Rule::in([User::ROL_ADMIN, User::ROL_SUPER_ADMIN, User::ROL_EMPLEADO])],
            'estado' => ['required', Rule::in([User::ESTADO_ACTIVO, User::ESTADO_INACTIVO])],
        ]);

        $actor = $request->user();
        if ($actor->id === $user->id && $data['rol'] !== $user->rol) {
            throw ValidationException::withMessages([
                'rol' => ['No puede cambiar su propio rol desde aquí.'],
            ]);
        }

        $user->nombre = trim($data['nombre']);
        $newCorreo = trim($data['correo']);
        if ($newCorreo !== $user->correo) {
            $user->correo_solicitado = null;
            $user->correo_solicitado_at = null;
        }
        $user->correo = $newCorreo;
        $user->rol = $data['rol'];
        $user->estado = $data['estado'];
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();

        return new UserResource($user);
    }

    public function updateEstado(Request $request, User $user): UserResource|JsonResponse
    {
        $data = $request->validate([
            'estado' => ['required', Rule::in([User::ESTADO_ACTIVO, User::ESTADO_INACTIVO])],
        ]);

        if ($request->user()->id === $user->id && $data['estado'] === User::ESTADO_INACTIVO) {
            return response()->json(['message' => 'No puede desactivar su propia cuenta.'], 422);
        }

        $user->estado = $data['estado'];
        $user->save();

        return new UserResource($user);
    }
}
