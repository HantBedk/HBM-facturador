<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminExportController extends Controller
{
    private const EXPORT_ROW_CAP = 10000;

    /**
     * CSV compatible con Excel (UTF-8 BOM, separador `;`). Filtros opcionales: empresa, empleado, periodo (año+mes) o rango de fechas.
     */
    public function services(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'company_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
            'user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'period_year' => ['sometimes', 'nullable', 'integer', 'min:2000', 'max:2100'],
            'period_month' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:12'],
            'service_date_from' => ['sometimes', 'nullable', 'date'],
            'service_date_to' => ['sometimes', 'nullable', 'date'],
        ]);

        if (isset($data['service_date_from'], $data['service_date_to']) && $data['service_date_to'] < $data['service_date_from']) {
            throw ValidationException::withMessages([
                'service_date_to' => ['La fecha final debe ser mayor o igual a la inicial.'],
            ]);
        }

        $hasPeriod = ! empty($data['period_year']) && ! empty($data['period_month']);
        $hasRange = ! empty($data['service_date_from']) && ! empty($data['service_date_to']);
        if (! empty($data['period_year']) xor ! empty($data['period_month'])) {
            throw ValidationException::withMessages([
                'period_year' => ['Para filtrar por mes indique año y mes.'],
            ]);
        }

        $q = Service::query()
            ->with(['company:id,nombre,nit', 'user:id,nombre']);

        if (! empty($data['company_id'])) {
            $q->where('company_id', $data['company_id']);
        }
        if (! empty($data['user_id'])) {
            $q->where('user_id', $data['user_id']);
        }

        if ($hasRange) {
            $q->whereBetween('service_date', [$data['service_date_from'], $data['service_date_to']]);
        } elseif ($hasPeriod) {
            $tz = config('app.timezone');
            $start = Carbon::createFromDate((int) $data['period_year'], (int) $data['period_month'], 1, $tz)->startOfMonth();
            $end = (clone $start)->endOfMonth();
            $q->whereBetween('service_date', [$start->toDateString(), $end->toDateString()]);
        }

        $filename = 'servicios-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($q) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Fecha servicio',
                'Empresa',
                'NIT empresa',
                'Código servicio',
                'Tipo',
                'Descripción',
                'Empleado',
                'Cliente u obra',
                'Valor',
                'Estado',
            ], ';');

            $n = 0;
            foreach ($q->orderByDesc('service_date')->orderByDesc('id')->cursor() as $svc) {
                if (++$n > self::EXPORT_ROW_CAP) {
                    break;
                }
                fputcsv($out, [
                    $svc->service_date?->format('Y-m-d'),
                    $svc->company?->nombre,
                    $svc->company?->nit,
                    $svc->code,
                    $svc->service_type,
                    $svc->description,
                    $svc->user?->nombre,
                    $svc->client_name,
                    (string) $svc->amount,
                    $svc->status,
                ], ';');
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Listado de facturas con totales y saldo (pagos agregados).
     */
    public function invoices(Request $request): StreamedResponse
    {
        $request->validate([
            'company_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
            'status' => ['sometimes', 'nullable', 'string', 'max:32'],
            'period_year' => ['sometimes', 'nullable', 'integer', 'min:2000', 'max:2100'],
            'period_month' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:12'],
            'q' => ['sometimes', 'nullable', 'string', 'max:128'],
        ]);

        $q = Invoice::query()
            ->with('company:id,nombre,nit')
            ->withSum('payments', 'amount');

        if ($request->filled('company_id')) {
            $q->where('company_id', $request->integer('company_id'));
        }
        if ($request->filled('status')) {
            $q->where('status', $request->string('status')->toString());
        }
        if ($request->filled('period_year')) {
            $q->where('period_year', $request->integer('period_year'));
        }
        if ($request->filled('period_month')) {
            $q->where('period_month', $request->integer('period_month'));
        }
        if ($request->filled('q')) {
            $raw = $request->string('q')->toString();
            $term = '%'.addcslashes($raw, '%_\\').'%';
            $q->where('code', 'like', $term);
        }

        $filename = 'facturas-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($q) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Código',
                'Empresa',
                'NIT',
                'Periodo',
                'Estado',
                'Total',
                'Total pagado',
                'Saldo pendiente',
                'Fecha creación',
            ], ';');

            $n = 0;
            foreach ($q->orderByDesc('period_year')->orderByDesc('period_month')->orderByDesc('id')->cursor() as $inv) {
                if (++$n > self::EXPORT_ROW_CAP) {
                    break;
                }
                $paid = (float) ($inv->payments_sum_amount ?? 0);
                $total = (float) $inv->total;
                $balance = max(0, $total - $paid);
                $period = $inv->period_month.'/'.$inv->period_year;
                fputcsv($out, [
                    $inv->code,
                    $inv->company?->nombre,
                    $inv->company?->nit,
                    $period,
                    $inv->status,
                    number_format($total, 2, '.', ''),
                    number_format($paid, 2, '.', ''),
                    number_format($balance, 2, '.', ''),
                    $inv->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                ], ';');
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
