<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\PanelNotification;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\PanelNotificationDispatcher;
use App\Support\InventoryInternalHolders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminInventoryHolderSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        $configured = InventoryInternalHolders::configuredUserIds();
        $usesExplicitList = $configured !== [];
        $idsForDisplay = $usesExplicitList ? $configured : InventoryInternalHolders::fallbackAdminUserIds();
        $holders = User::query()
            ->whereIn('id', $idsForDisplay)
            ->where('estado', User::ESTADO_ACTIVO)
            ->orderBy('nombre')
            ->get();

        return response()->json([
            'data' => [
                'uses_explicit_list' => $usesExplicitList,
                'holder_user_ids' => $holders->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                'holders' => $holders->map(fn (User $u) => [
                    'id' => $u->id,
                    'nombre' => $u->nombre,
                    'correo' => $u->correo,
                    'rol' => $u->rol,
                ])->values()->all(),
            ],
            'help' => 'Defina quiénes pueden figurar como titular al registrar activos del inventario interno. '
                .'Si no guarda una lista explícita, se usan todos los administradores activos.',
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'holder_user_ids' => ['required', 'array', 'min:1', 'max:30'],
            'holder_user_ids.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        if (! Hash::check($data['current_password'], $actor->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['La contraseña no coincide con su usuario.'],
            ]);
        }

        $ids = collect($data['holder_user_ids'])->map(fn ($id) => (int) $id)->unique()->values()->all();
        $users = User::query()->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($ids as $id) {
            $u = $users->get($id);
            if ($u === null || $u->estado !== User::ESTADO_ACTIVO) {
                throw ValidationException::withMessages([
                    'holder_user_ids' => ['Todos los titulares deben ser usuarios existentes y estar activos.'],
                ]);
            }
        }

        AppSetting::setJsonValue(AppSetting::KEY_INVENTORY_HOLDERS, $ids);

        $nombres = collect($ids)
            ->map(fn (int $id) => $users->get($id)?->nombre)
            ->filter()
            ->implode(', ');

        ActivityLogger::log(
            $actor,
            'inventario_titulares_actualizados',
            'Actualizó titulares de inventario interno: '.$nombres.'.'
        );
        app(PanelNotificationDispatcher::class)->notifyAdmins(
            PanelNotification::TYPE_INVENTORY_HOLDERS_UPDATED,
            $actor->nombre.' actualizó la lista de titulares de inventario.',
            [
                'link' => '/admin/configuracion/inventario',
                'holders_count' => count($ids),
            ]
        );

        return response()->json([
            'message' => 'Titulares guardados correctamente.',
            'data' => [
                'uses_explicit_list' => true,
                'holder_user_ids' => $ids,
                'holders' => User::query()
                    ->whereIn('id', $ids)
                    ->orderBy('nombre')
                    ->get()
                    ->map(fn (User $u) => [
                        'id' => $u->id,
                        'nombre' => $u->nombre,
                        'correo' => $u->correo,
                        'rol' => $u->rol,
                    ])->values()->all(),
            ],
        ]);
    }
}
