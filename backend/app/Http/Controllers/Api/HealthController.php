<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Monitorización ligera (JSON). Sin datos sensibles.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $dbOk = true;
        try {
            DB::connection()->getPdo();
        } catch (\Throwable) {
            $dbOk = false;
        }

        $payload = [
            'status' => $dbOk ? 'ok' : 'degraded',
            'database' => $dbOk ? 'ok' : 'unreachable',
            'app' => config('app.name'),
            'time' => now()->toIso8601String(),
        ];

        return response()->json($payload, $dbOk ? 200 : 503);
    }
}
