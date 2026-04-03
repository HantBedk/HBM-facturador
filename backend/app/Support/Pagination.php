<?php

namespace App\Support;

use Illuminate\Http\Request;

final class Pagination
{
    /**
     * Limita `per_page` para evitar consultas masivas desde la API.
     */
    public static function perPage(Request $request, int $default = 15, int $max = 100): int
    {
        $v = (int) $request->input('per_page', $default);
        if ($v < 1) {
            return $default;
        }

        return min($max, $v);
    }
}
