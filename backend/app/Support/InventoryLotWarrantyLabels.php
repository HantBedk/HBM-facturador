<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Semáforo de garantía para listados y PDF (sin cron: se calcula al serializar).
 */
final class InventoryLotWarrantyLabels
{
    public const SEMAPHORE_SIN_DATO = 'sin_dato';

    public const SEMAPHORE_VIGENTE = 'vigente';

    public const SEMAPHORE_POR_VENCER = 'por_vencer';

    public const SEMAPHORE_VENCIDA = 'vencida';

    /**
     * @param  \DateTimeInterface|string|null  $warrantyUntil
     */
    public static function semaphore(mixed $warrantyUntil): string
    {
        if ($warrantyUntil === null || $warrantyUntil === '') {
            return self::SEMAPHORE_SIN_DATO;
        }
        try {
            $end = $warrantyUntil instanceof \DateTimeInterface
                ? Carbon::parse($warrantyUntil->format('Y-m-d'))->startOfDay()
                : Carbon::parse((string) $warrantyUntil)->startOfDay();
        } catch (\Throwable) {
            return self::SEMAPHORE_SIN_DATO;
        }
        $today = Carbon::now()->startOfDay();
        if ($end->lt($today)) {
            return self::SEMAPHORE_VENCIDA;
        }
        if ($end->lte($today->copy()->addDays(30))) {
            return self::SEMAPHORE_POR_VENCER;
        }

        return self::SEMAPHORE_VIGENTE;
    }

    public static function labelEs(string $semaphore): string
    {
        return match ($semaphore) {
            self::SEMAPHORE_VIGENTE => 'Garantía vigente',
            self::SEMAPHORE_POR_VENCER => 'Garantía por vencer (≤30 días)',
            self::SEMAPHORE_VENCIDA => 'Garantía vencida',
            default => 'Sin fecha de garantía',
        };
    }
}
