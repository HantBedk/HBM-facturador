<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\PanelNotification;
use App\Services\PanelNotificationDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BillingProcessCutoffCommand extends Command
{
    protected $signature = 'billing:process-cutoff';

    protected $description = 'Marca como ENVIADA las facturas APROBADA el día de corte (si está habilitado).';

    public function handle(PanelNotificationDispatcher $dispatcher): int
    {
        if (! config('automation.cutoff_enabled')) {
            $this->info('Corte automático desactivado (AUTOMATION_CUTOFF_ENABLED).');

            return self::SUCCESS;
        }

        $cutoffDay = (int) config('automation.cutoff_day');
        $cutoffDay = max(1, min(28, $cutoffDay));

        $today = now()->timezone(config('app.timezone'));
        if ((int) $today->day !== $cutoffDay) {
            return self::SUCCESS;
        }

        $count = 0;
        DB::transaction(function () use (&$count) {
            Invoice::query()
                ->where('status', Invoice::STATUS_APROBADA)
                ->orderBy('id')
                ->each(function (Invoice $invoice) use (&$count) {
                    $invoice->status = Invoice::STATUS_ENVIADA;
                    if ($invoice->sent_at === null) {
                        $invoice->sent_at = now();
                    }
                    $invoice->save();
                    $count++;
                });
        });

        if ($count > 0) {
            $dispatcher->notifyAdmins(
                PanelNotification::TYPE_CUTOFF_AUTO_SENT,
                "Corte automático: se marcaron {$count} factura(s) aprobada(s) como enviadas.",
                ['count' => $count],
                'cutoff_auto_'.$today->format('Y-m-d')
            );
            $this->info("Marcadas {$count} factura(s) como enviadas.");
        } else {
            $this->info('No hay facturas en estado aprobada para el corte.');
        }

        return self::SUCCESS;
    }
}
