<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\PanelNotification;
use App\Models\Service;
use App\Services\PanelNotificationDispatcher;
use Carbon\Carbon;
use Illuminate\Console\Command;

class BillingDailyRemindersCommand extends Command
{
    protected $signature = 'billing:daily-reminders';

    protected $description = 'Recordatorios de corte, borradores, aprobadas sin enviar y alertas de datos.';

    public function handle(PanelNotificationDispatcher $dispatcher): int
    {
        $tz = config('app.timezone');
        $today = Carbon::now($tz)->startOfDay();

        $this->remindCutoffApproaching($dispatcher, $today, $tz);
        $this->alertDraftInvoices($dispatcher, $today);
        $this->alertApprovedUnsent($dispatcher, $today);
        $this->alertServicesZeroAmount($dispatcher, $today);

        return self::SUCCESS;
    }

    private function remindCutoffApproaching(PanelNotificationDispatcher $dispatcher, Carbon $today, string $tz): void
    {
        $cutoffDay = max(1, min(28, (int) config('automation.cutoff_day')));
        $reminderDays = max(0, (int) config('automation.cutoff_reminder_days_before'));

        if ($reminderDays < 1) {
            return;
        }

        $year = (int) $today->year;
        $month = (int) $today->month;
        $lastDay = (int) $today->daysInMonth;
        $day = min($cutoffDay, $lastDay);

        $cutoffDate = Carbon::create($year, $month, $day, 0, 0, 0, $tz);
        $notifyDate = $cutoffDate->copy()->subDays($reminderDays);

        if (! $today->equalTo($notifyDate->startOfDay())) {
            return;
        }

        $dedupe = 'cutoff_approaching_'.$today->format('Y-m-d');
        $draftCount = Invoice::query()->where('status', Invoice::STATUS_BORRADOR)->count();

        $msg = 'Se acerca el corte de facturación ('.$cutoffDate->format('d/m/Y').'). Revise y apruebe borradores a tiempo.';
        if ($draftCount > 0) {
            $msg .= " Hay {$draftCount} factura(s) en borrador sin aprobar.";
        }

        $dispatcher->notifyAdmins(
            PanelNotification::TYPE_CUTOFF_APPROACHING,
            $msg,
            ['cutoff_date' => $cutoffDate->toDateString(), 'draft_count' => $draftCount],
            $dedupe
        );
    }

    private function alertDraftInvoices(PanelNotificationDispatcher $dispatcher, Carbon $today): void
    {
        $n = Invoice::query()->where('status', Invoice::STATUS_BORRADOR)->count();
        if ($n === 0) {
            return;
        }

        $dedupe = 'alert_drafts_'.$today->format('Y-m-d');
        $dispatcher->notifyAdmins(
            PanelNotification::TYPE_ALERT_DRAFTS_PENDING,
            "Tiene {$n} factura(s) en borrador pendiente(s) de aprobación.",
            ['count' => $n, 'link' => '/admin/facturas'],
            $dedupe
        );
    }

    private function alertApprovedUnsent(PanelNotificationDispatcher $dispatcher, Carbon $today): void
    {
        $n = Invoice::query()->where('status', Invoice::STATUS_APROBADA)->count();
        if ($n === 0) {
            return;
        }

        $dedupe = 'alert_approved_unsent_'.$today->format('Y-m-d');
        $dispatcher->notifyAdmins(
            PanelNotification::TYPE_ALERT_APPROVED_UNSENT,
            "Hay {$n} factura(s) aprobada(s) pendiente(s) de envío (o de corte automático).",
            ['count' => $n, 'link' => '/admin/facturas'],
            $dedupe
        );
    }

    private function alertServicesZeroAmount(PanelNotificationDispatcher $dispatcher, Carbon $today): void
    {
        $n = Service::query()
            ->whereIn('status', [Service::STATUS_ACTIVO, Service::STATUS_CORREGIDO])
            ->where(function ($q) {
                $q->whereNull('amount')->orWhere('amount', '<=', 0);
            })
            ->count();

        if ($n === 0) {
            return;
        }

        $dedupe = 'alert_services_zero_'.$today->format('Y-m-d');
        $dispatcher->notifyAdmins(
            PanelNotification::TYPE_ALERT_SERVICES_ZERO,
            "Atención: {$n} servicio(s) activo(s) con valor en cero o inválido. Revise el listado de servicios.",
            ['count' => $n, 'link' => '/admin/servicios'],
            $dedupe
        );
    }
}
