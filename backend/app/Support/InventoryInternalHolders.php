<?php

namespace App\Support;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Titulares de inventario interno: lista explícita en {@see AppSetting::KEY_INVENTORY_HOLDERS}
 * o, si está vacía, todos los administradores activos.
 */
final class InventoryInternalHolders
{
    /**
     * IDs guardados en configuración. Vacío = no hay lista explícita (modo respaldo).
     *
     * @return list<int>
     */
    public static function configuredUserIds(): array
    {
        $value = AppSetting::query()->where('key', AppSetting::KEY_INVENTORY_HOLDERS)->value('value');
        if (! is_array($value) || $value === []) {
            return [];
        }

        return collect($value)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Administradores activos usados cuando no hay lista explícita.
     *
     * @return list<int>
     */
    public static function fallbackAdminUserIds(): array
    {
        return User::query()
            ->where('estado', User::ESTADO_ACTIVO)
            ->whereIn('rol', [User::ROL_ADMIN, User::ROL_SUPER_ADMIN])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * IDs candidatos (config o respaldo), sin filtrar por existencia.
     *
     * @return list<int>
     */
    public static function rawEffectiveUserIds(): array
    {
        $configured = self::configuredUserIds();

        return $configured !== [] ? $configured : self::fallbackAdminUserIds();
    }

    /**
     * Titulares que pueden figurar como dueños de lote (solo usuarios activos existentes).
     *
     * @return list<int>
     */
    public static function effectiveUserIds(): array
    {
        $raw = self::rawEffectiveUserIds();
        if ($raw === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $raw)
            ->where('estado', User::ESTADO_ACTIVO)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, User>
     */
    public static function effectiveUsersOrdered(): Collection
    {
        $ids = self::effectiveUserIds();
        if ($ids === []) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $ids)
            ->where('estado', User::ESTADO_ACTIVO)
            ->orderBy('nombre')
            ->get();
    }

    public static function userMayHoldInventory(User $user): bool
    {
        if ($user->estado !== User::ESTADO_ACTIVO) {
            return false;
        }

        $allowed = self::effectiveUserIds();

        return in_array((int) $user->id, $allowed, true);
    }
}
