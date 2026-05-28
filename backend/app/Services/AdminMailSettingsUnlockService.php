<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Ventana temporal tras confirmar contraseña para ver/editar configuración de correo del sistema.
 */
class AdminMailSettingsUnlockService
{
    private const TTL_SECONDS = 3600;

    private function cacheKey(int $userId): string
    {
        return 'hbm:admin_mail_settings_unlocked:'.$userId;
    }

    public function isUnlocked(User $user): bool
    {
        return Cache::has($this->cacheKey((int) $user->id));
    }

    public function unlockWithPassword(User $user, string $plainPassword): void
    {
        if (! Hash::check($plainPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['La contraseña no coincide con su usuario.'],
            ]);
        }

        Cache::put($this->cacheKey((int) $user->id), time(), self::TTL_SECONDS);
    }

    public function assertUnlocked(User $user): void
    {
        if ($this->isUnlocked($user)) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'Debe confirmar su contraseña para acceder a la configuración de correo.',
            'code' => 'mail_config_locked',
        ], 403));
    }
}
