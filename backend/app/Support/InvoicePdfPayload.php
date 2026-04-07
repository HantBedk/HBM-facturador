<?php

namespace App\Support;

use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;

/**
 * Estructura de datos para PDF y respuestas JSON de factura (consulta pública y panel admin).
 */
class InvoicePdfPayload
{
    /**
     * @param  array{preview?: bool}  $options  preview: marca agua en PDF (panel admin, borradores).
     * @return array<string, mixed>
     */
    public static function build(Invoice $invoice, array $options = []): array
    {
        $preview = (bool) ($options['preview'] ?? false);

        $invoice->loadMissing([
            'company',
            'services.user',
            'services.catalog',
            'payments' => fn ($q) => $q->orderBy('payment_date'),
        ]);

        $totalPaid = (string) $invoice->payments->sum('amount');
        $total = (string) $invoice->total;
        $balance = max(0, (float) $invoice->total - (float) $totalPaid);
        $subtotalNum = (float) $invoice->subtotal;
        $totalNum = (float) $invoice->total;
        $taxNum = round(max(0, $totalNum - $subtotalNum), 2);

        $services = $invoice->services->map(function ($s) {
            $date = $s->service_date;
            try {
                $pdfDate = $date
                    ? Carbon::parse($date)->timezone(config('app.timezone'))->format('d/m/y')
                    : '—';
            } catch (\Throwable) {
                $pdfDate = '—';
            }

            $typeLabel = trim((string) ($s->service_type ?? ''));
            $catalogName = trim((string) ($s->catalog?->name ?? ''));
            $title = $typeLabel !== '' ? $typeLabel : ($catalogName !== '' ? $catalogName : 'Servicio');

            $detail = trim((string) ($s->description ?? ''));
            $client = trim((string) ($s->client_name ?? ''));
            if ($client !== '') {
                $detail = $detail !== '' ? $detail.' ('.$client.')' : '('.$client.')';
            }

            $amountStr = (string) $s->amount;

            return [
                'service_date' => $s->service_date?->format('Y-m-d'),
                'code' => $s->code,
                'description' => $s->description,
                'service_type' => $s->service_type,
                'technician_name' => $s->user?->nombre,
                'amount' => $amountStr,
                'pdf_date_label' => $pdfDate,
                'pdf_title' => $title,
                'pdf_detail' => $detail !== '' ? $detail : null,
                'pdf_qty' => 1,
                'pdf_unit' => $amountStr,
                'pdf_line_total' => $amountStr,
            ];
        })->values()->all();

        $payments = $invoice->payments->map(function ($p) {
            return [
                'payment_date' => $p->payment_date?->format('Y-m-d'),
                'amount' => (string) $p->amount,
                'method' => $p->method,
                'notes' => $p->notes,
            ];
        })->values()->all();

        $issuedAt = $invoice->created_at;
        $issuedTz = $issuedAt?->timezone(config('app.timezone'));
        $issuedLabel = $issuedTz ? $issuedTz->format('d/m/y') : '';
        $issuedTimeLabel = $issuedTz ? $issuedTz->format('g:i A') : '';
        $periodLabel = self::invoicePeriodLabel((int) $invoice->period_month, (int) $invoice->period_year);

        return [
            'issuer' => self::issuerBlock(),
            'invoice' => [
                'code' => $invoice->code,
                'number' => $invoice->code,
                'status' => $invoice->status,
                'status_label' => self::invoiceStatusLabel($invoice->status),
                'period_month' => $invoice->period_month,
                'period_year' => $invoice->period_year,
                'period_label' => $periodLabel,
                'period_label_upper' => SafeUtf8::upper($periodLabel),
                'sent_at' => $invoice->sent_at?->toIso8601String(),
                'issued_at' => $issuedAt?->toIso8601String(),
                'issued_at_label' => $issuedLabel,
                'issued_time_label' => $issuedTimeLabel,
            ],
            'document' => [
                'is_preview' => $preview,
                'is_public_copy' => false,
            ],
            'company' => [
                'nombre' => $invoice->company?->nombre,
                'nit' => $invoice->company?->nit,
                'telefono' => $invoice->company?->telefono,
                'correo' => $invoice->company?->correo,
                /** Reservado; el PDF ya no muestra nombres agregados aquí (técnico por línea en `services`). */
                'contact' => null,
                'direccion' => data_get($invoice->company, 'direccion'),
            ],
            'services' => $services,
            'products' => [],
            'financial' => [
                'subtotal' => (string) $invoice->subtotal,
                'total' => $total,
                'total_paid' => $totalPaid,
                'balance' => number_format($balance, 2, '.', ''),
                'tax_amount' => number_format($taxNum, 2, '.', ''),
                'tax_label' => $taxNum > 0.0001 ? 'IVA / otros cargos' : 'IVA (0%)',
            ],
            'payments' => $payments,
            'footer' => [
                'message' => (string) config('billing.footer_message'),
                'payment_terms' => (string) config('billing.payment_terms'),
                'payment_methods' => config('billing.pdf_payment_methods', []),
            ],
        ];
    }

    /**
     * @return array{nombre: string, nit: string, direccion: string, telefono: string, correo: string, regimen: string, logo_data_uri: ?string}
     */
    private static function issuerBlock(): array
    {
        $cfg = config('billing.issuer', []);

        return [
            'nombre' => (string) ($cfg['nombre'] ?? ''),
            'nit' => (string) ($cfg['nit'] ?? ''),
            'direccion' => (string) ($cfg['direccion'] ?? ''),
            'telefono' => (string) ($cfg['telefono'] ?? ''),
            'correo' => (string) ($cfg['correo'] ?? ''),
            'regimen' => (string) ($cfg['regimen'] ?? ''),
            'logo_data_uri' => self::logoDataUri(),
        ];
    }

    private static function logoDataUri(): ?string
    {
        $path = (string) config('billing.logo_path', '');
        if ($path === '') {
            return null;
        }

        $full = self::isAbsolutePath($path)
            ? $path
            : public_path(ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR));

        if (! is_readable($full)) {
            return null;
        }

        $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/png',
        };

        $binary = @File::get($full);
        if ($binary === false || $binary === '') {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($binary);
    }

    private static function isAbsolutePath(string $path): bool
    {
        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
    }

    public static function invoiceStatusLabel(string $status): string
    {
        return match ($status) {
            Invoice::STATUS_BORRADOR => 'Borrador',
            Invoice::STATUS_APROBADA => 'Aprobada',
            Invoice::STATUS_ENVIADA => 'Enviada',
            Invoice::STATUS_PARCIALMENTE_PAGADA => 'Parcialmente pagada',
            Invoice::STATUS_PAGADA => 'Pagada',
            default => $status,
        };
    }

    public static function invoicePeriodLabel(int $month, int $year): string
    {
        $months = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
        ];
        $name = $months[$month] ?? (string) $month;

        return $name.' '.$year;
    }
}
