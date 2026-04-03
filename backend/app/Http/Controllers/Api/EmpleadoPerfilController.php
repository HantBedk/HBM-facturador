<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PanelNotification;
use App\Models\User;
use App\Services\PanelNotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmpleadoPerfilController extends Controller
{
    private const DOCUMENT_TYPES = ['CC', 'CE', 'TI', 'PASAPORTE', 'PPT', 'PT', 'PEP', 'OTRO'];

    private const ACCOUNT_TYPES = ['ahorros', 'corriente', 'llave_breb'];

    /**
     * Catálogos para el formulario (compartido con panel admin).
     *
     * @return array<string, mixed>
     */
    public static function catalogPayload(): array
    {
        return [
            'banks' => config('colombia_banks'),
            'document_types' => [
                ['codigo' => 'CC', 'nombre' => 'Cédula de ciudadanía'],
                ['codigo' => 'CE', 'nombre' => 'Cédula de extranjería'],
                ['codigo' => 'TI', 'nombre' => 'Tarjeta de identidad'],
                ['codigo' => 'PASAPORTE', 'nombre' => 'Pasaporte'],
                ['codigo' => 'PPT', 'nombre' => 'Permiso por protección temporal (PPT)'],
                ['codigo' => 'PT', 'nombre' => 'Permiso temporal de trabajo'],
                ['codigo' => 'PEP', 'nombre' => 'Permiso especial de permanencia (PEP)'],
                ['codigo' => 'OTRO', 'nombre' => 'Otro documento'],
            ],
            'account_types' => [
                ['codigo' => 'ahorros', 'nombre' => 'Cuenta de ahorros'],
                ['codigo' => 'corriente', 'nombre' => 'Cuenta corriente'],
                ['codigo' => 'llave_breb', 'nombre' => 'Llave Bre-B (Bre-B)'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function profileFromUser(User $user): array
    {
        return [
            'nombre' => $user->nombre,
            'telefono' => $user->telefono,
            'tipo_documento' => $user->tipo_documento,
            'numero_documento' => $user->numero_documento,
            'ciudad' => $user->ciudad,
            'departamento' => $user->departamento,
            'banco_codigo' => $user->banco_codigo,
            'cuenta_tipo' => $user->cuenta_tipo,
            'cuenta_numero' => $user->cuenta_numero,
            'perfil_completado_at' => $user->perfil_completado_at?->toIso8601String(),
        ];
    }

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->rol !== User::ROL_EMPLEADO) {
            return response()->json(['message' => 'Solo disponible para técnicos.'], 403);
        }

        return response()->json(array_merge(self::catalogPayload(), [
            'profile' => self::profileFromUser($user),
        ]));
    }

    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->rol !== User::ROL_EMPLEADO) {
            return response()->json(['message' => 'Solo disponible para técnicos.'], 403);
        }

        $bankCodes = collect(config('colombia_banks'))->pluck('codigo')->all();

        $data = $request->validate([
            'nombre' => ['required', 'string', 'min:2', 'max:255'],
            'telefono' => ['required', 'string', 'min:10', 'max:24'],
            'tipo_documento' => ['required', 'string', Rule::in(self::DOCUMENT_TYPES)],
            'numero_documento' => ['required', 'string', 'min:5', 'max:32', 'regex:/^[A-Za-z0-9.\s\-]+$/u'],
            'ciudad' => ['required', 'string', 'max:120'],
            'departamento' => ['nullable', 'string', 'max:120'],
            'banco_codigo' => ['required', 'string', Rule::in($bankCodes)],
            'cuenta_tipo' => ['required', 'string', Rule::in(self::ACCOUNT_TYPES)],
            'cuenta_numero' => ['required', 'string', 'min:4', 'max:191'],
        ]);

        self::validateCuentaNumeroFormat($data['cuenta_tipo'], trim($data['cuenta_numero']));

        $telefono = preg_replace('/\s+/', '', trim($data['telefono']));
        $digitos = preg_replace('/\D/', '', $telefono);
        if (strlen($digitos) < 10) {
            throw ValidationException::withMessages([
                'telefono' => ['Indica un número de contacto válido (mínimo 10 dígitos).'],
            ]);
        }

        $firstProfileCompletion = $user->perfil_completado_at === null;

        $user->nombre = trim($data['nombre']);
        $user->telefono = strlen($digitos) >= 10 ? $digitos : $telefono;
        $user->tipo_documento = $data['tipo_documento'];
        $user->numero_documento = trim($data['numero_documento']);
        $user->ciudad = trim($data['ciudad']);
        $user->departamento = isset($data['departamento']) ? trim((string) $data['departamento']) : null;
        $user->banco_codigo = $data['banco_codigo'];
        $user->cuenta_tipo = $data['cuenta_tipo'];
        $user->cuenta_numero = trim($data['cuenta_numero']);
        $user->perfil_completado_at = now();
        $user->save();

        if ($firstProfileCompletion) {
            app(PanelNotificationDispatcher::class)->notifyAdmins(
                PanelNotification::TYPE_EMPLEADO_PERFIL_COMPLETADO,
                'El técnico '.trim($user->nombre).' completó su perfil de contacto y datos de pago.',
                ['link' => '/admin/empleados/'.$user->id.'/perfil', 'empleado_id' => $user->id],
            );
        }

        return response()->json([
            'message' => 'Perfil guardado correctamente.',
            'user' => [
                'id' => $user->id,
                'nombre' => $user->nombre,
                'correo' => $user->correo,
                'rol' => $user->rol,
                'estado' => $user->estado,
                'perfil_completado_at' => $user->perfil_completado_at?->toIso8601String(),
                'telefono' => $user->telefono,
                'tipo_documento' => $user->tipo_documento,
                'numero_documento' => $user->numero_documento,
                'ciudad' => $user->ciudad,
                'departamento' => $user->departamento,
                'banco_codigo' => $user->banco_codigo,
                'cuenta_tipo' => $user->cuenta_tipo,
                'cuenta_numero' => $user->cuenta_numero,
            ],
        ]);
    }

    /**
     * Llave Bre-B: correo, celular o identificador con letras, números y símbolos habituales.
     * Cuenta bancaria clásica: solo letras, números y guiones.
     */
    public static function validateCuentaNumeroFormat(string $cuentaTipo, string $cn): void
    {
        if (strlen($cn) < 4) {
            throw ValidationException::withMessages([
                'cuenta_numero' => ['Indica al menos 4 caracteres.'],
            ]);
        }

        if ($cuentaTipo === 'llave_breb') {
            if (! preg_match('/^[\p{L}\p{N}@._+\-\s]+$/u', $cn)) {
                throw ValidationException::withMessages([
                    'cuenta_numero' => ['Usa letras, números, espacios, @, punto, guiones o + (correo o llave Bre-B).'],
                ]);
            }
        } elseif (! preg_match('/^[0-9A-Za-z\-]+$/', $cn)) {
            throw ValidationException::withMessages([
                'cuenta_numero' => ['Solo números, letras y guiones.'],
            ]);
        }
    }
}
