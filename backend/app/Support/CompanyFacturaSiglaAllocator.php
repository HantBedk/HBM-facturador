<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Asigna siglas únicas de 3 letras (A-Z) para códigos de factura YYMMDD-XXX-NNN.
 */
final class CompanyFacturaSiglaAllocator
{
    /**
     * @param  array<string, true>  $used  claves = siglas ya tomadas
     */
    public static function allocate(?string $nombre, int $companyId, array &$used): string
    {
        $base = self::baseFromNombre($nombre);
        $candidates = array_merge(
            [$base],
            self::variantsFromBase($base),
            [self::fromSequentialId($companyId)],
            self::extraVariants($companyId)
        );

        foreach ($candidates as $c) {
            if (strlen($c) !== 3 || ! ctype_alpha($c)) {
                continue;
            }
            $u = strtoupper($c);
            if (isset($used[$u])) {
                continue;
            }
            $used[$u] = true;

            return $u;
        }

        throw new \RuntimeException('No se pudo asignar factura_sigla única para la empresa ID '.$companyId.'.');
    }

    public static function baseFromNombre(?string $nombre): string
    {
        $ascii = Str::upper(Str::ascii((string) $nombre));
        $alpha = preg_replace('/[^A-Z]/', '', $ascii) ?? '';
        if (strlen($alpha) < 3) {
            $alpha = str_pad($alpha, 3, 'X');
        }

        return substr($alpha, 0, 3);
    }

    /**
     * @return list<string>
     */
    private static function variantsFromBase(string $base): array
    {
        $out = [];
        for ($i = 0; $i < 26; $i++) {
            $out[] = substr($base, 0, 2).chr(65 + $i);
        }
        for ($i = 0; $i < 26; $i++) {
            $out[] = substr($base, 0, 1).chr(65 + $i).substr($base, 2, 1);
        }
        for ($i = 0; $i < 26; $i++) {
            $out[] = chr(65 + $i).substr($base, 1, 2);
        }

        return $out;
    }

    private static function fromSequentialId(int $companyId): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $n = max(0, $companyId - 1);
        $a = $chars[$n % 26];
        $n = intdiv($n, 26);
        $b = $chars[$n % 26];
        $n = intdiv($n, 26);
        $c = $chars[$n % 26];

        return $a.$b.$c;
    }

    /**
     * @return list<string>
     */
    private static function extraVariants(int $companyId): array
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $out = [];
        $salt = $companyId * 7;
        for ($k = 0; $k < 200; $k++) {
            $i = ($salt + $k) % 26;
            $j = ($salt + $k * 3) % 26;
            $m = ($salt + $k * 5) % 26;
            $out[] = $chars[$i].$chars[$j].$chars[$m];
        }

        return $out;
    }
}
