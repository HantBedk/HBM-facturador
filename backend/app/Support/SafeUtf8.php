<?php

namespace App\Support;

/**
 * Evita fallos si falta ext-mbstring (poco frecuente) y normaliza texto para PDF/HTML.
 */
final class SafeUtf8
{
    public static function upper(string $value): string
    {
        if (function_exists('mb_strtoupper')) {
            return mb_strtoupper($value, 'UTF-8');
        }

        return strtoupper($value);
    }

    public static function lower(string $value): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtolower($value);
    }
}
