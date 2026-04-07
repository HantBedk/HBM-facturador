<?php

namespace App\Console\Commands;

use App\Services\AutomaticInvoiceDraftService;
use App\Services\AutomationSettings;
use Illuminate\Console\Command;

class BillingGenerateDraftInvoicesCommand extends Command
{
    protected $signature = 'billing:generate-draft-invoices';

    protected $description = 'Crea borradores de factura por empresa (servicios del periodo sin facturar), el día configurado.';

    public function handle(AutomaticInvoiceDraftService $drafts, AutomationSettings $automation): int
    {
        if (! $automation->draftGenerationEnabled()) {
            $this->info('Generación automática de borradores desactivada (panel Configuración o AUTOMATION_DRAFT_GENERATION_ENABLED).');

            return self::SUCCESS;
        }

        $tz = config('app.timezone');
        $today = now()->timezone($tz);

        $day = $automation->draftGenerationDay();
        $lastDay = (int) $today->daysInMonth;
        $effectiveDay = min($day, $lastDay);

        if ((int) $today->day !== $effectiveDay) {
            return self::SUCCESS;
        }

        $result = $drafts->run($today);

        if ($result['created'] > 0) {
            $this->info('Creados '.$result['created'].' borrador(es): '.implode(', ', $result['codes']));
        } else {
            $this->info('Ningún borrador nuevo (sin servicios pendientes o ya existía borrador del periodo).');
        }

        return self::SUCCESS;
    }
}
