<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\AutomationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminBillingAutomationController extends Controller
{
    public function show(AutomationSettings $automation): JsonResponse
    {
        return response()->json([
            'data' => [
                'draft_generation_enabled' => $automation->draftGenerationEnabled(),
                'draft_generation_day' => $automation->draftGenerationDay(),
                'draft_generation_period' => $automation->draftGenerationPeriod(),
                'stored_in_database' => $automation->draftSettingsStoredInDatabase(),
            ],
            'help' => 'Día del mes (1–28) en que el servidor intenta crear borradores (~05:00, APP_TIMEZONE); requiere cron con php artisan schedule:run. Valores guardados aquí sustituyen al .env. Cada guardado queda en el historial de actividad (usuario y texto).',
        ]);
    }

    public function update(Request $request, AutomationSettings $automation): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'draft_generation_enabled' => ['required', 'boolean'],
            'draft_generation_day' => ['required', 'integer', 'min:1', 'max:28'],
            'draft_generation_period' => ['required', 'in:current,previous'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        if (! Hash::check($data['current_password'], $actor->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['La contraseña no coincide con su usuario.'],
            ]);
        }

        $day = max(1, min(28, (int) $data['draft_generation_day']));

        AppSetting::setJsonValue(
            AppSetting::KEY_AUTOMATION_DRAFT_GENERATION_ENABLED,
            (bool) $data['draft_generation_enabled']
        );
        AppSetting::setJsonValue(AppSetting::KEY_AUTOMATION_DRAFT_GENERATION_DAY, $day);
        AppSetting::setJsonValue(
            AppSetting::KEY_AUTOMATION_DRAFT_PERIOD,
            $data['draft_generation_period']
        );

        ActivityLogger::log(
            $actor,
            'automation_facturacion_actualizada',
            'Actualizó automatización de borradores: activo='.($data['draft_generation_enabled'] ? 'sí' : 'no').", día={$day}, periodo={$data['draft_generation_period']}."
        );

        return response()->json([
            'message' => 'Configuración de facturación automática guardada.',
            'data' => [
                'draft_generation_enabled' => $automation->draftGenerationEnabled(),
                'draft_generation_day' => $automation->draftGenerationDay(),
                'draft_generation_period' => $automation->draftGenerationPeriod(),
                'stored_in_database' => $automation->draftSettingsStoredInDatabase(),
            ],
        ]);
    }
}
