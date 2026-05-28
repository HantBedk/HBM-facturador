<?php

namespace App\Support;

/**
 * Suma decimales con escala fija. Usa BCMath si está disponible; si no, enteros escalados (sin float).
 */
final class DecimalMath
{
    public static function add(string $left, string $right, int $scale = 2): string
    {
        if (\function_exists('bcadd')) {
            return \bcadd($left, $right, $scale);
        }

        $sum = self::toScaledInt($left, $scale) + self::toScaledInt($right, $scale);

        return self::fromScaledInt($sum, $scale);
    }

    /**
     * Multiplica dos decimales con escala fija (p. ej. precio × cantidad).
     * Sin BCMath: para cantidad entera usa aritmética en enteros escalados; en otro caso, fallback seguro a float.
     */
    public static function mul(string $left, string $right, int $scale = 2): string
    {
        if (\function_exists('bcmul')) {
            return \bcmul($left, $right, $scale);
        }

        $rTrim = trim($right);
        if (preg_match('/^-?\d+$/', $rTrim)) {
            $scaled = self::toScaledInt($left, $scale);

            return self::fromScaledInt($scaled * (int) $rTrim, $scale);
        }

        return number_format((float) $left * (float) $right, $scale, '.', '');
    }

    private static function toScaledInt(string $decimal, int $scale): int
    {
        $s = trim($decimal);
        $sign = 1;
        if ($s !== '' && $s[0] === '-') {
            $sign = -1;
            $s = substr($s, 1);
        }
        $s = ltrim($s, '+');
        if ($s === '') {
            return 0;
        }

        [$whole, $frac] = array_pad(explode('.', $s, 2), 2, '');
        $whole = $whole === '' ? '0' : $whole;
        if (! preg_match('/^\d+$/', $whole)) {
            throw new \InvalidArgumentException('Importe decimal inválido.');
        }
        $fracDigits = preg_replace('/\D/', '', $frac) ?? '';
        $fracDigits = substr(str_pad($fracDigits, $scale, '0'), 0, $scale);

        $multiplier = 10 ** $scale;

        return $sign * (((int) $whole * $multiplier) + (int) $fracDigits);
    }

    private static function fromScaledInt(int $scaled, int $scale): string
    {
        $sign = '';
        if ($scaled < 0) {
            $sign = '-';
            $scaled = -$scaled;
        }
        $multiplier = 10 ** $scale;
        $whole = intdiv($scaled, $multiplier);
        $frac = $scaled % $multiplier;

        return $sign.$whole.'.'.str_pad((string) $frac, $scale, '0', STR_PAD_LEFT);
    }
}
