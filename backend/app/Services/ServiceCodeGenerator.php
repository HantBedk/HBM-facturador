<?php

namespace App\Services;

use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ServiceCodeGenerator
{
    public const KIND_SERVICIO = 'servicio';

    public const KIND_VENTA = 'venta';

    public const KIND_ALQUILER = 'alquiler';

    /**
     * @param  self::KIND_*  $kind
     *
     * Formato {SERV|VENT|ALQ}-YYMMDDNN: fecha (2 dígitos año), consecutivo por prefijo y día (01-99).
     */
    public function nextForDate(Carbon $serviceDate, string $kind = self::KIND_SERVICIO): string
    {
        $slug = match ($kind) {
            self::KIND_VENTA => 'VENT',
            self::KIND_ALQUILER => 'ALQ',
            default => 'SERV',
        };

        $local = $serviceDate->copy()->timezone(config('app.timezone'))->startOfDay();
        $datePart = sprintf(
            '%02d%02d%02d',
            $local->year % 100,
            (int) $local->format('n'),
            (int) $local->format('j')
        );
        $prefix = $slug.'-'.$datePart;
        $expectedLen = strlen($prefix) + 2;
        $pattern = '/^'.preg_quote($slug, '/').'-\d{6}(\d{2})$/';

        return DB::transaction(function () use ($prefix, $expectedLen, $pattern, $slug) {
            $lastCode = Service::query()
                ->where('code', 'like', $prefix.'%')
                ->whereRaw('LENGTH(code) = ?', [$expectedLen])
                ->orderByDesc('code')
                ->lockForUpdate()
                ->value('code');

            $next = 1;
            if ($lastCode !== null && preg_match($pattern, $lastCode, $m)) {
                $next = (int) $m[1] + 1;
            }

            if ($next > 99) {
                throw new \RuntimeException('Consecutivo '.$slug.' agotado para esta fecha (máx. 99).');
            }

            return $prefix.str_pad((string) $next, 2, '0', STR_PAD_LEFT);
        });
    }
}
