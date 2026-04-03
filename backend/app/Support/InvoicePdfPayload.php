<?php

namespace App\Support;

use App\Models\Invoice;
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

        $invoice->loadMissing(['company', 'services.user', 'payments' => fn ($q) => $q->orderBy('payment_date')]);

        $totalPaid = (string) $invoice->payments->sum('amount');
        $total = (string) $invoice->total;
        $balance = max(0, (float) $invoice->total - (float) $totalPaid);

        $services = $invoice->services->map(function ($s) {
            return [
                'service_date' => $s->service_date?->format('Y-m-d'),
                'code' => $s->code,
                'description' => $s->description,
                'service_type' => $s->service_type,
                'technician_name' => $s->user?->nombre,
                'amount' => (string) $s->amount,
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

        $clientNames = $invoice->services->pluck('client_name')->filter()->unique()->values()->all();
        $clientContact = $clientNames !== [] ? implode(', ', $clientNames) : null;

        $issuedAt = $invoice->created_at;
        $issuedLabel = $issuedAt ? $issuedAt->timezone(config('app.timezone'))->format('d/m/Y') : '';

        return [
            'issuer' => self::issuerBlock(),
            'invoice' => [
                'code' => $invoice->code,
                'number' => $invoice->code,
                'status' => $invoice->status,
                'status_label' => self::statusLabel($invoice->status),
                'period_month' => $invoice->period_month,
                'period_year' => $invoice->period_year,
                'period_label' => self::periodLabel((int) $invoice->period_month, (int) $invoice->period_year),
                'sent_at' => $invoice->sent_at?->toIso8601String(),
                'issued_at' => $issuedAt?->toIso8601String(),
                'issued_at_label' => $issuedLabel,
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
                'contact' => $clientContact,
            ],
            'services' => $services,
            'products' => [],
            'financial' => [
                'subtotal' => (string) $invoice->subtotal,
                'total' => $total,
                'total_paid' => $totalPaid,
                'balance' => number_format($balance, 2, '.', ''),
            ],
            'payments' => $payments,
            'footer' => [
                'message' => (string) config('billing.footer_message'),
                'payment_terms' => (string) config('billing.payment_terms'),
            ],
        ];
    }

    /**
     * @return array{nombre: string, nit: string, direccion: string, telefono: string, correo: string, logo_data_uri: ?string}
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

    private static function statusLabel(string $status): string
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

    private static function periodLabel(int $month, int $year): string
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
