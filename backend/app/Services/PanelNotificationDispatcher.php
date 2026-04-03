<?php

namespace App\Services;

use App\Models\PanelNotification;
use App\Models\User;

class PanelNotificationDispatcher
{
    /**
     * @return list<int>
     */
    public function adminUserIds(): array
    {
        return User::query()
            ->whereIn('rol', [User::ROL_ADMIN, User::ROL_SUPER_ADMIN])
            ->where('estado', User::ESTADO_ACTIVO)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function notifyAdmins(string $type, string $message, array $meta = [], ?string $dedupeKey = null): void
    {
        foreach ($this->adminUserIds() as $userId) {
            $key = $dedupeKey !== null ? $dedupeKey.'_user_'.$userId : null;
            if ($key !== null && PanelNotification::query()->where('dedupe_key', $key)->exists()) {
                continue;
            }

            PanelNotification::query()->create([
                'user_id' => $userId,
                'type' => $type,
                'message' => $message,
                'read' => false,
                'meta' => $meta !== [] ? $meta : null,
                'dedupe_key' => $key,
            ]);
        }
    }
}
