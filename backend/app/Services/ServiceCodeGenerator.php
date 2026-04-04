<?php

namespace App\Services;

use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ServiceCodeGenerator
{
    /**
     * Formato SERV-YYMMDDNN: prefijo, fecha del servicio (2 dígitos año), consecutivo global del día (01-99).
     */
    public function nextForDate(Carbon $serviceDate): string
    {
        $local = $serviceDate->copy()->timezone(config('app.timezone'))->startOfDay();
        $datePart = sprintf(
            '%02d%02d%02d',
            $local->year % 100,
            (int) $local->format('n'),
            (int) $local->format('j')
        );
        $prefix = 'SERV-'.$datePart;
        $expectedLen = strlen($prefix) + 2;

        return DB::transaction(function () use ($prefix, $expectedLen) {
            $lastCode = Service::query()
                ->where('code', 'like', $prefix.'%')
                ->whereRaw('LENGTH(code) = ?', [$expectedLen])
                ->orderByDesc('code')
                ->lockForUpdate()
                ->value('code');

            $next = 1;
            if ($lastCode !== null && preg_match('/^SERV-\d{6}(\d{2})$/', $lastCode, $m)) {
                $next = (int) $m[1] + 1;
            }

            if ($next > 99) {
                throw new \RuntimeException('Consecutivo de servicios agotado para esta fecha (máx. 99).');
            }

            return $prefix.str_pad((string) $next, 2, '0', STR_PAD_LEFT);
        });
    }
}
