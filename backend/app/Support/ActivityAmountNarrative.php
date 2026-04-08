<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Formato de importes solo para descripciones de ActivityLog (auditoría legible).
 */
final class ActivityAmountNarrative
{
    public static function cop(float|string|null $amount): string
    {
        $n = is_numeric($amount) ? (float) $amount : 0.0;

        return number_format($n, 2, ',', '.').' COP';
    }
}
