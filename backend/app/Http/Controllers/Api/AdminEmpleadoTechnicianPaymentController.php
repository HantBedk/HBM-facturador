<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\TechnicianAbonoNotifier;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminEmpleadoTechnicianPaymentController extends Controller
{
    /**
     * Servicios del técnico con importe de referencia pendiente de registro de pago.
     */
    public function pendingServices(Request $request, User $user): JsonResponse
    {
        if (! $request->user()->isAdminEquipo()) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }
        if ($user->rol !== User::ROL_EMPLEADO) {
            return response()->json(['message' => 'Solo aplica a cuentas con rol técnico.'], 422);
        }

        $rows = Service::query()
            ->where('user_id', $user->id)
            ->visibles()
            ->whereNull('technician_paid_at')
            ->with(['company:id,nombre'])
            ->withSum('items', 'technician_line_amount')
            ->withCount('items')
            ->orderByDesc('service_date')
            ->orderByDesc('id')
            ->get();

        $data = [];
        foreach ($rows as $s) {
            $v = $s->technicianReferenceTotalValue();
            if ($v <= 0.00001) {
                continue;
            }
            $data[] = [
                'id' => $s->id,
                'code' => $s->code,
                'service_date' => $s->service_date?->format('Y-m-d'),
                'company_name' => $s->company?->nombre,
                'technician_line_total' => number_format($v, 2, '.', ''),
            ];
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Registra la misma fecha de abono de referencia en varios servicios del técnico.
     */
    public function batchPay(Request $request, User $user): JsonResponse
    {
        if (! $request->user()->isAdminEquipo()) {
            return response()->json(['message' => 'Solo el equipo administrador puede registrar este estado.'], 403);
        }
        if ($user->rol !== User::ROL_EMPLEADO) {
            return response()->json(['message' => 'Solo aplica a cuentas con rol técnico.'], 422);
        }

        $data = $request->validate([
            'service_ids' => ['required', 'array', 'min:1'],
            'service_ids.*' => ['integer'],
            'technician_paid_at' => ['required', 'date'],
        ]);

        $paidAt = Carbon::parse($data['technician_paid_at'], config('app.timezone'))->startOfDay();
        $ids = array_values(array_unique($data['service_ids']));

        $services = Service::query()
            ->whereIn('id', $ids)
            ->where('user_id', $user->id)
            ->visibles()
            ->whereNull('technician_paid_at')
            ->withSum('items', 'technician_line_amount')
            ->withCount('items')
            ->get()
            ->keyBy('id');

        if ($services->count() !== count($ids)) {
            return response()->json([
                'message' => 'Uno o más servicios no existen o no pueden abonarse (deben ser del técnico, visibles y sin pago previo).',
            ], 422);
        }

        foreach ($services as $s) {
            if ($s->technicianReferenceTotalValue() <= 0.00001) {
                return response()->json([
                    'message' => 'El servicio '.$s->code.' no tiene importe de referencia técnico.',
                ], 422);
            }
        }

        $notifier = app(TechnicianAbonoNotifier::class);
        $actor = $request->user();

        DB::transaction(function () use ($services, $paidAt, $actor, $notifier) {
            foreach ($services as $service) {
                $service->technician_paid_at = $paidAt;
                $service->save();
                $service->refresh()->loadSum('items', 'technician_line_amount')->loadCount('items');
                $notifier->notifyRegisteredAbono($service, $actor);
                ActivityLogger::log(
                    $actor,
                    'servicio_pago_tecnico',
                    'Marcó pago al técnico para servicio '.$service->code.' (ID '.$service->id.').'
                );
            }
        });

        return response()->json([
            'message' => 'Se registraron los abonos de referencia seleccionados.',
            'updated' => $services->count(),
        ]);
    }
}
