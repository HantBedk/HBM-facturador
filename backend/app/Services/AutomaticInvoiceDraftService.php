<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\PanelNotification;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AutomaticInvoiceDraftService
{
    public function __construct(
        private InvoiceCodeGenerator $codes,
        private PanelNotificationDispatcher $dispatcher,
        private AutomationSettings $automation,
    ) {}

    /**
     * Crea un borrador por empresa (periodo configurado) con servicios visibles,
     * con importe > 0 y aún no vinculados a ninguna factura.
     *
     * @return array{created: int, codes: list<string>}
     */
    public function run(Carbon $today): array
    {
        $tz = config('app.timezone');
        $today = $today->copy()->timezone($tz);

        [$year, $month] = $this->resolvePeriod($today);

        $start = Carbon::createFromDate($year, $month, 1, $tz)->startOfMonth();
        $end = (clone $start)->endOfMonth();

        $blockedIds = DB::table('invoice_service')->pluck('service_id')->all();

        $created = 0;
        $codesOut = [];

        foreach (Company::query()->activas()->cursor() as $company) {
            $sigla = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $company->factura_sigla) ?? '');
            if (strlen($sigla) !== 3 || ! ctype_alpha($sigla)) {
                continue;
            }

            if ($this->companyHasDraftForPeriod((int) $company->id, $year, $month)) {
                continue;
            }

            $candidates = Service::query()
                ->where('company_id', $company->id)
                ->whereBetween('service_date', [$start->toDateString(), $end->toDateString()])
                ->visibles()
                ->where('amount', '>', 0)
                ->orderBy('id')
                ->pluck('id')
                ->all();

            $serviceIds = array_values(array_filter(
                $candidates,
                fn (int $id) => ! in_array($id, $blockedIds, true)
            ));

            if ($serviceIds === []) {
                continue;
            }

            try {
                DB::transaction(function () use ($company, $year, $month, $serviceIds, $tz, &$created, &$codesOut) {
                    $code = $this->codes->nextForCompanyOnDate($company, Carbon::now($tz));
                    $total = (string) Service::query()->whereIn('id', $serviceIds)->sum('amount');
                    $inv = Invoice::query()->create([
                        'code' => $code,
                        'company_id' => $company->id,
                        'period_month' => $month,
                        'period_year' => $year,
                        'status' => Invoice::STATUS_BORRADOR,
                        'subtotal' => $total,
                        'total' => $total,
                        'sent_at' => null,
                    ]);
                    $inv->services()->sync($serviceIds);
                    $created++;
                    $codesOut[] = $code;
                });
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if ($created > 0) {
            $dedupe = 'auto_invoice_drafts_'.$today->format('Y-m-d');
            $this->dispatcher->notifyAdmins(
                PanelNotification::TYPE_INVOICE_DRAFT,
                "Borradores automáticos ({$month}/{$year}): se crearon {$created} factura(s). Revise y apruebe en Facturación.",
                [
                    'period_month' => $month,
                    'period_year' => $year,
                    'count' => $created,
                    'codes' => $codesOut,
                    'link' => '/admin/facturas',
                ],
                $dedupe
            );

            ActivityLogger::log(
                null,
                'factura_auto_borrador',
                "Sistema: generó {$created} borrador(es) automático(s) para periodo {$month}/{$year}."
            );
        }

        return ['created' => $created, 'codes' => $codesOut];
    }

    /**
     * @return array{0: int, 1: int} year, month (1-12)
     */
    private function resolvePeriod(Carbon $today): array
    {
        $mode = $this->automation->draftGenerationPeriod();
        if ($mode === 'previous') {
            $d = $today->copy()->subMonthNoOverflow()->startOfMonth();

            return [(int) $d->year, (int) $d->format('n')];
        }

        return [(int) $today->year, (int) $today->month];
    }

    private function companyHasDraftForPeriod(int $companyId, int $year, int $month): bool
    {
        return Invoice::query()
            ->where('company_id', $companyId)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->where('status', Invoice::STATUS_BORRADOR)
            ->exists();
    }
}
