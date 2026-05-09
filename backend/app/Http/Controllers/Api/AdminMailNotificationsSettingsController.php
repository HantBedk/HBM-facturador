<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminMailNotificationsSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        $row = AppSetting::query()->where('key', AppSetting::KEY_MAIL_NOTIFICATIONS_FROM)->first();
        $stored = is_array($row?->value) ? $row->value : [];
        $addr = trim((string) ($stored['address'] ?? ''));
        $name = trim((string) ($stored['name'] ?? ''));

        return response()->json([
            'data' => [
                'from_address' => $addr,
                'from_name' => $name,
                'effective_from_address' => $this->effectiveAddress($addr),
                'effective_from_name' => $this->effectiveName($name),
            ],
            'help' => 'Dirección y nombre usados como remitente en correos del sistema (OTP inventario empresa, facturas enviadas, recuperación de clave, etc.). Debe coincidir con un remitente verificado en su proveedor SMTP (p. ej. Brevo). Si deja la dirección vacía, se usa MAIL_FROM_ADDRESS del .env.',
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'from_address' => ['nullable', 'string', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:120'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        if (! Hash::check($data['current_password'], $actor->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['La contraseña no coincide con su usuario.'],
            ]);
        }

        $addr = trim((string) ($data['from_address'] ?? ''));
        if ($addr !== '' && ! filter_var($addr, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'from_address' => ['Indique un correo electrónico válido o déjelo vacío para usar el .env.'],
            ]);
        }

        $name = trim((string) ($data['from_name'] ?? ''));

        AppSetting::setJsonValue(AppSetting::KEY_MAIL_NOTIFICATIONS_FROM, [
            'address' => $addr,
            'name' => $name,
        ]);

        ActivityLogger::log(
            $actor,
            'correo_notificaciones_actualizado',
            'Actualizó remitente de correos del sistema: dirección='.($addr !== '' ? $addr : '(env)').', nombre='.($name !== '' ? $name : '(env)').'.'
        );

        return response()->json([
            'message' => 'Configuración de correo guardada.',
            'data' => [
                'from_address' => $addr,
                'from_name' => $name,
                'effective_from_address' => $this->effectiveAddress($addr),
                'effective_from_name' => $this->effectiveName($name),
            ],
        ]);
    }

    private function effectiveAddress(string $stored): string
    {
        if ($stored !== '' && filter_var($stored, FILTER_VALIDATE_EMAIL)) {
            return $stored;
        }

        return (string) config('mail.from.address', '');
    }

    private function effectiveName(string $stored): string
    {
        if (trim($stored) !== '') {
            return $stored;
        }

        return (string) config('mail.from.name', '');
    }
}
