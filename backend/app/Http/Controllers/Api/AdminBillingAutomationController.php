<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\AutomationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            'help' => 'El día indicado es el del mes (1–28) en que el programador del servidor ejecuta la creación de borradores (aprox. 05:00, zona APP_TIMEZONE). Requiere que el cron ejecute php artisan schedule:run. Si no guarda aquí, se usan AUTOMATION_* del .env.',
        ]);
    }

    public function update(Request $request, AutomationSettings $automation): JsonResponse
    {
        $data = $request->validate([
            'draft_generation_enabled' => ['required', 'boolean'],
            'draft_generation_day' => ['required', 'integer', 'min:1', 'max:28'],
            'draft_generation_period' => ['required', 'in:current,previous'],
        ]);

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

        /** @var User $actor */
        $actor = $request->user();
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
