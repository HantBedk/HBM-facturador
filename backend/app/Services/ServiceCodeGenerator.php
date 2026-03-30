<?php

namespace App\Services;

use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ServiceCodeGenerator
{
    /**
     * Código AAAAMMDD-NNN único según la fecha del servicio (zona horaria de la app).
     */
    public function nextForDate(Carbon $serviceDate): string
    {
        $local = $serviceDate->copy()->timezone(config('app.timezone'))->startOfDay();
        $prefix = $local->format('Ymd');

        return DB::transaction(function () use ($prefix) {
            $lastCode = Service::query()
                ->where('code', 'like', $prefix.'-%')
                ->orderByDesc('code')
                ->lockForUpdate()
                ->value('code');

            $next = 1;
            if ($lastCode !== null && str_contains($lastCode, '-')) {
                $suffix = (int) substr($lastCode, strrpos($lastCode, '-') + 1);
                $next = $suffix + 1;
            }

            return $prefix.'-'.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
        });
    }
}
