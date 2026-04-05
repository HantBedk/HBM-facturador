<?php

/**
 * Corte mensual de facturación y recordatorios (Etapa 7).
 */
return [
    /** Día del mes (1–28 recomendado) en que se ejecuta el corte automático. */
    'cutoff_day' => (int) env('AUTOMATION_CUTOFF_DAY', 28),

    /** Si está activo, las facturas en APROBADA pasan a ENVIADA el día de corte. */
    'cutoff_enabled' => filter_var(env('AUTOMATION_CUTOFF_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    /**
     * Días antes del corte para avisar de proximidad (y revisar borradores).
     * Ej.: corte 28 y valor 3 → aviso el día 25.
     */
    'cutoff_reminder_days_before' => (int) env('AUTOMATION_CUTOFF_REMINDER_DAYS', 3),

    /**
     * Generación automática de borradores: un borrador por empresa con servicios
     * del periodo aún no incluidos en ninguna factura (requiere cron schedule:run).
     */
    'draft_generation_enabled' => filter_var(env('AUTOMATION_DRAFT_GENERATION_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    /**
     * Día del mes (1–28) en que se crean esos borradores.
     * Por defecto toma el mismo valor que AUTOMATION_CUTOFF_DAY si no define AUTOMATION_DRAFT_GENERATION_DAY.
     */
    'draft_generation_day' => max(1, min(28, (int) env('AUTOMATION_DRAFT_GENERATION_DAY', env('AUTOMATION_CUTOFF_DAY', 28)))),

    /**
     * Periodo a facturar: mes calendario actual (current) o el anterior (previous).
     */
    'draft_generation_period' => strtolower((string) env('AUTOMATION_DRAFT_PERIOD', 'current')) === 'previous'
        ? 'previous'
        : 'current',
];
