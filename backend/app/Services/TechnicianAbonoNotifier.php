<?php

namespace App\Services;

use App\Models\PanelNotification;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;

/**
 * Aviso al técnico cuando administración registra abono de referencia (`technician_paid_at`).
 */
class TechnicianAbonoNotifier
{
    public function notifyRegisteredAbono(Service $service, User $admin): void
    {
        if (! $admin->isAdminEquipo() || $service->technician_paid_at === null) {
            return;
        }

        $service->loadSum('items', 'technician_line_amount');
        $service->loadCount('items');
        $refTotal = $service->technicianReferenceTotalValue();
        if ($refTotal <= 0.00001) {
            return;
        }

        $paid = $service->technician_paid_at instanceof Carbon
            ? $service->technician_paid_at
            : Carbon::parse((string) $service->technician_paid_at, config('app.timezone'))->startOfDay();

        $amountFmt = number_format($refTotal, 0, ',', '.');
        $fechaFmt = $paid->timezone(config('app.timezone'))->format('d/m/Y');
        $msg = 'Administración registró el abono de referencia por su servicio '.$service->code.': '.$amountFmt.' COP (fecha registrada: '.$fechaFmt.').';
        $dedupeKey = 'emp_abono_tecn_'.$service->id.'_'.$paid->format('Y-m-d');

        $ownerId = (int) $service->user_id;
        if ($ownerId === 0 || $ownerId === (int) $admin->id) {
            return;
        }
        $owner = User::query()->find($ownerId);
        if ($owner === null || $owner->rol !== User::ROL_EMPLEADO) {
            return;
        }

        app(PanelNotificationDispatcher::class)->notifyUser(
            $ownerId,
            PanelNotification::TYPE_EMP_ABONO_TECNICO_REGISTRADO,
            $msg,
            [
                'service_id' => $service->id,
                'link' => '/empleado/servicio/'.$service->id,
                'technician_paid_at' => $paid->toDateString(),
                'amount' => number_format($refTotal, 2, '.', ''),
            ],
            $dedupeKey
        );
    }
}
