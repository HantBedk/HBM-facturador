<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\InventoryLot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Consulta pública de inventario por empresa: NIT + OTP al correo registrado en la ficha de la empresa.
 * Sin precios en la respuesta (custodia); solo estado operativo del activo.
 */
class PublicCompanyInventoryController extends Controller
{
    private const OTP_TTL_SECONDS = 600;

    private const SESSION_TTL_SECONDS = 1800;

    public function requestOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nit' => ['required', 'string', 'max:32'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        $nitKey = $this->normalizeNit($validated['nit']);
        $emailKey = strtolower(trim($validated['email']));

        $this->hitRateLimit('inv-pub-otp-ip:'.$request->ip(), 10, 3600);
        $this->hitRateLimit('inv-pub-otp-nit:'.$nitKey, 6, 3600);

        $company = $this->findActiveCompanyByNitKey($nitKey);

        $uniform = [
            'message' => 'Si el NIT y el correo coinciden con un registro activo, recibirá un código en breve.',
            'expires_in' => self::OTP_TTL_SECONDS,
        ];

        if ($company !== null && $this->emailMatchesCompany($company, $emailKey)) {
            $otp = (string) random_int(100000, 999999);
            $payload = [
                'otp' => $otp,
                'company_id' => (int) $company->id,
                'email' => $emailKey,
            ];
            Cache::put($this->otpCacheKey($nitKey, $emailKey), $payload, self::OTP_TTL_SECONDS);

            try {
                Mail::raw(
                    "Su código de verificación para consultar el inventario es: {$otp}\n\nVálido por 10 minutos.",
                    function ($message) use ($company, $emailKey) {
                        $message->to($emailKey)->subject('Código de consulta — inventario '.$company->nombre);
                    }
                );
            } catch (\Throwable $e) {
                report($e);
                Cache::forget($this->otpCacheKey($nitKey, $emailKey));
                throw ValidationException::withMessages([
                    'email' => ['No se pudo enviar el correo en este momento. Intente más tarde o contacte a soporte.'],
                ]);
            }
        }

        return response()->json($uniform);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nit' => ['required', 'string', 'max:32'],
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $nitKey = $this->normalizeNit($validated['nit']);
        $emailKey = strtolower(trim($validated['email']));
        $code = trim($validated['code']);

        $this->hitRateLimit('inv-pub-verify-ip:'.$request->ip(), 20, 3600);

        $cached = Cache::get($this->otpCacheKey($nitKey, $emailKey));
        if (! is_array($cached) || empty($cached['otp']) || empty($cached['company_id'])) {
            throw ValidationException::withMessages([
                'code' => ['Código inválido o expirado. Solicite uno nuevo.'],
            ]);
        }

        if (! hash_equals((string) $cached['otp'], $code)) {
            throw ValidationException::withMessages([
                'code' => ['Código incorrecto.'],
            ]);
        }

        Cache::forget($this->otpCacheKey($nitKey, $emailKey));

        $token = Str::random(48);
        Cache::put($this->sessionCacheKey($token), (int) $cached['company_id'], self::SESSION_TTL_SECONDS);

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => self::SESSION_TTL_SECONDS,
        ]);
    }

    public function lots(Request $request): JsonResponse
    {
        $token = $this->bearerToken($request);
        if ($token === null || $token === '') {
            abort(401, 'Token requerido.');
        }

        $companyId = Cache::get($this->sessionCacheKey($token));
        if (! $companyId) {
            abort(401, 'Sesión expirada o token inválido.');
        }

        $rows = InventoryLot::query()
            ->where('tenant_company_id', (int) $companyId)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'sku',
                'quantity_available',
                'lifecycle_status',
                'is_active',
                'created_at',
            ]);

        $data = $rows->map(fn (InventoryLot $lot) => [
            'id' => $lot->id,
            'name' => $lot->name,
            'sku' => $lot->sku,
            'quantity_available' => (int) $lot->quantity_available,
            'lifecycle_status' => (string) ($lot->lifecycle_status ?? InventoryLot::LIFECYCLE_ACTIVO),
            'is_active' => (bool) $lot->is_active,
            'created_at' => $lot->created_at?->toIso8601String(),
        ])->values()->all();

        return response()->json(['data' => $data]);
    }

    private function bearerToken(Request $request): ?string
    {
        $h = $request->header('Authorization', '');
        if (is_string($h) && str_starts_with($h, 'Bearer ')) {
            return trim(substr($h, 7));
        }

        return null;
    }

    private function otpCacheKey(string $nitKey, string $emailKey): string
    {
        return 'company_inv_otp:'.hash('sha256', $nitKey.'|'.$emailKey.'|'.config('app.key'));
    }

    private function sessionCacheKey(string $token): string
    {
        return 'company_inv_sess:'.hash('sha256', $token.config('app.key'));
    }

    private function normalizeNit(string $nit): string
    {
        return preg_replace('/\D+/', '', $nit) ?? '';
    }

    private function findActiveCompanyByNitKey(string $nitKey): ?Company
    {
        if ($nitKey === '') {
            return null;
        }

        return Company::query()
            ->activas()
            ->get()
            ->first(function (Company $c) use ($nitKey) {
                return $this->normalizeNit((string) $c->nit) === $nitKey;
            });
    }

    private function emailMatchesCompany(Company $company, string $emailLower): bool
    {
        $correo = strtolower(trim((string) ($company->correo ?? '')));

        return $correo !== '' && hash_equals($correo, $emailLower);
    }

    private function hitRateLimit(string $key, int $max, int $decaySeconds): void
    {
        if (RateLimiter::tooManyAttempts($key, $max)) {
            abort(429, 'Demasiados intentos. Espere e intente de nuevo.');
        }
        RateLimiter::hit($key, $decaySeconds);
    }
}
