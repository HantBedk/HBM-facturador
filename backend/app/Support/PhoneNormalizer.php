<?php

namespace App\Support;

/**
 * Clave estable para emparejar ventas sin empresa (solo dígitos, sin duplicar filas en `companies`).
 */
final class PhoneNormalizer
{
    public static function digitsKey(string $telefonoRaw): string
    {
        $digits = preg_replace('/\D/', '', $telefonoRaw);

        return is_string($digits) ? $digits : '';
    }
}
