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
];
