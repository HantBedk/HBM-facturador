<?php

namespace App\Services;

use App\Models\AppSetting;

/**
 * Valores efectivos de automatización de facturación: si existen filas en app_settings,
 * tienen prioridad sobre config/automation.php y variables .env.
 */
class AutomationSettings
{
    public function draftGenerationEnabled(): bool
    {
        $key = AppSetting::KEY_AUTOMATION_DRAFT_GENERATION_ENABLED;
        if (! AppSetting::query()->where('key', $key)->exists()) {
            return (bool) config('automation.draft_generation_enabled');
        }

        return AppSetting::getBool($key, false);
    }

    public function draftGenerationDay(): int
    {
        $key = AppSetting::KEY_AUTOMATION_DRAFT_GENERATION_DAY;
        $fallback = max(1, min(28, (int) config('automation.draft_generation_day')));
        if (! AppSetting::query()->where('key', $key)->exists()) {
            return $fallback;
        }

        return max(1, min(28, AppSetting::getInt($key, $fallback)));
    }

    public function draftGenerationPeriod(): string
    {
        $key = AppSetting::KEY_AUTOMATION_DRAFT_PERIOD;
        $fallback = config('automation.draft_generation_period') === 'previous' ? 'previous' : 'current';
        if (! AppSetting::query()->where('key', $key)->exists()) {
            return $fallback;
        }

        $v = strtolower(AppSetting::getString($key, $fallback));

        return $v === 'previous' ? 'previous' : 'current';
    }

    /**
     * @return array<string, bool>
     */
    public function draftSettingsStoredInDatabase(): array
    {
        return [
            'draft_generation_enabled' => AppSetting::query()
                ->where('key', AppSetting::KEY_AUTOMATION_DRAFT_GENERATION_ENABLED)
                ->exists(),
            'draft_generation_day' => AppSetting::query()
                ->where('key', AppSetting::KEY_AUTOMATION_DRAFT_GENERATION_DAY)
                ->exists(),
            'draft_generation_period' => AppSetting::query()
                ->where('key', AppSetting::KEY_AUTOMATION_DRAFT_PERIOD)
                ->exists(),
        ];
    }
}
