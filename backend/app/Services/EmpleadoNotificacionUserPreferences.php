<?php

namespace App\Services;

use App\Models\PanelNotification;
use App\Models\User;

/**
 * Preferencias por técnico: dentro de lo que el admin permite, elige qué avisos quiere recibir.
 */
class EmpleadoNotificacionUserPreferences
{
    public function shouldReceive(int $userId, string $type): bool
    {
        if (! in_array($type, PanelNotification::empleadoNotificationTypes(), true)) {
            return true;
        }

        if (! app(EmpleadoNotificacionSettings::class)->isEnabled($type)) {
            return false;
        }

        $user = User::query()->find($userId);
        if ($user === null) {
            return false;
        }

        $prefs = $user->empleado_notificaciones_prefs;
        if (! is_array($prefs)) {
            return true;
        }

        return ($prefs[$type] ?? true) === true;
    }

    /**
     * @return list<array{type: string, label: string, admin_allows: bool, user_wants: bool, can_edit: bool}>
     */
    public function getItemsForUser(User $user): array
    {
        $admin = app(EmpleadoNotificacionSettings::class)->getMap();
        $labels = EmpleadoNotificacionSettings::labels();
        $stored = is_array($user->empleado_notificaciones_prefs) ? $user->empleado_notificaciones_prefs : [];

        $out = [];
        foreach (PanelNotification::empleadoNotificationTypes() as $type) {
            $adminAllows = ($admin[$type] ?? true) === true;
            $userWantsStored = ($stored[$type] ?? true) === true;
            $out[] = [
                'type' => $type,
                'label' => $labels[$type] ?? $type,
                'admin_allows' => $adminAllows,
                'user_wants' => $adminAllows ? $userWantsStored : false,
                'can_edit' => $adminAllows,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, bool>  $types
     */
    public function saveForUser(User $user, array $types): void
    {
        $admin = app(EmpleadoNotificacionSettings::class)->getMap();
        $current = is_array($user->empleado_notificaciones_prefs) ? $user->empleado_notificaciones_prefs : [];

        foreach (PanelNotification::empleadoNotificationTypes() as $type) {
            if (! ($admin[$type] ?? true)) {
                continue;
            }
            if (array_key_exists($type, $types)) {
                $current[$type] = (bool) $types[$type];
            }
        }

        $user->empleado_notificaciones_prefs = $current;
        $user->save();
    }
}
