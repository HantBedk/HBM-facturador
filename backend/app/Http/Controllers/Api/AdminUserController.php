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
    /** El rol admin no gestiona otras cuentas admin ni super_admin (sí la propia vía API si aplica). */
    private function isActorAdminLimited(Request $request): bool
    {
        return $request->user()?->rol === User::ROL_ADMIN;
    }

    private function denyIfAdminCannotManageTarget(Request $request, User $target): void
    {
        if (! $this->isActorAdminLimited($request)) {
            return;
        }
        $actor = $request->user();
        if ($target->isAdminEquipo() && $actor->id !== $target->id) {
            abort(403, 'No tiene permiso para gestionar cuentas de administrador.');
        }
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = User::query();

        if ($this->isActorAdminLimited($request)) {
            $q->where('rol', User::ROL_EMPLEADO);
        }

        if ($request->filled('user_id')) {
            $q->where('id', $request->integer('user_id'));
        } elseif ($request->filled('q')) {
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

        $sort = $request->query('sort');
        $sortDir = strtolower((string) $request->query('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        $allowedSorts = ['nombre', 'correo', 'rol', 'estado', 'created_at'];
        if (is_string($sort) && in_array($sort, $allowedSorts, true)) {
            $q->orderBy($sort, $sortDir)->orderBy('id', $sortDir);
        } else {
            $q->orderBy('nombre')->orderBy('id');
        }

        return UserResource::collection(
            $q->paginate(Pagination::perPage($request))->withQueryString()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $rolRule = $request->user()->rol === User::ROL_SUPER_ADMIN
            ? Rule::in([User::ROL_ADMIN, User::ROL_SUPER_ADMIN, User::ROL_EMPLEADO])
            : Rule::in([User::ROL_EMPLEADO]);

        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'correo' => ['required', 'string', 'email:filter', 'max:255', 'unique:users,correo'],
            'password' => ['required', 'string', 'min:8'],
            'rol' => ['required', $rolRule],
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
        $this->denyIfAdminCannotManageTarget($request, $user);

        $actor = $request->user();
        $rolRule = Rule::in([User::ROL_ADMIN, User::ROL_SUPER_ADMIN, User::ROL_EMPLEADO]);
        if ($actor->rol === User::ROL_ADMIN) {
            if ($user->isAdminEquipo() && $actor->id === $user->id) {
                $rolRule = Rule::in([User::ROL_ADMIN]);
            } else {
                $rolRule = Rule::in([User::ROL_EMPLEADO]);
            }
        }

        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'correo' => ['required', 'string', 'email:filter', 'max:255', Rule::unique('users', 'correo')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8'],
            'rol' => ['required', $rolRule],
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
        $this->denyIfAdminCannotManageTarget($request, $user);

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
