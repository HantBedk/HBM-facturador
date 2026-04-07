<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Invoice */
class AdminInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $issuer = config('billing.issuer', []);

        return [
            'id' => $this->id,
            'code' => $this->code,
            'company_id' => $this->company_id,
            /** Factura sin fila en `companies` (venta mostrador); el PDF usa estos datos. */
            'bill_to' => [
                'nombre' => $this->bill_to_nombre,
                'telefono' => $this->bill_to_telefono,
                'nit' => $this->bill_to_nit,
            ],
            'company' => $this->whenLoaded('company', fn () => $this->company ? [
                'id' => $this->company->id,
                'nombre' => $this->company->nombre,
                'nit' => $this->company->nit,
                'telefono' => $this->company->telefono,
                'correo' => $this->company->correo,
                'es_cliente_puntual' => (bool) ($this->company->es_cliente_puntual ?? false),
            ] : null),
            'period_month' => $this->period_month,
            'period_year' => $this->period_year,
            'period_label' => $this->periodLabel((int) $this->period_month, (int) $this->period_year),
            'status' => $this->status,
            'status_label' => $this->statusLabel($this->status),
            'subtotal' => (string) $this->subtotal,
            'total' => (string) $this->total,
            'sent_at' => $this->sent_at?->toIso8601String(),
            /** Indica si ya existe código de verificación para consulta pública (el valor nunca se expone por API). */
            'public_access_configured' => $this->public_access_token !== null && $this->public_access_token !== '',
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'services' => $this->whenLoaded('services', function () {
                return $this->services->map(fn ($s) => [
                    'id' => $s->id,
                    'code' => $s->code,
                    'catalog_id' => $s->catalog_id,
                    'catalog' => $s->relationLoaded('catalog') && $s->catalog ? [
                        'id' => $s->catalog->id,
                        'name' => $s->catalog->name,
                    ] : null,
                    'service_date' => $s->service_date?->format('Y-m-d'),
                    'description' => $s->description,
                    'service_type' => $s->service_type,
                    'client_name' => $s->client_name,
                    'contact_phone_key' => $s->contact_phone_key,
                    'client_telefono' => $s->client_telefono,
                    'amount' => (string) $s->amount,
                    'empleado' => $s->user ? ['nombre' => $s->user->nombre] : null,
                ])->values()->all();
            }),
            'payments' => $this->whenLoaded('payments', function () {
                return $this->payments->map(fn ($p) => [
                    'id' => $p->id,
                    'amount' => (string) $p->amount,
                    'payment_date' => $p->payment_date?->format('Y-m-d'),
                    'method' => $p->method,
                    'notes' => $p->notes,
                ])->values()->all();
            }),
            'financial' => $this->whenLoaded('payments', function () {
                $paid = (float) $this->payments->sum('amount');
                $total = (float) $this->total;

                return [
                    'total_paid' => (string) $paid,
                    'balance' => number_format(max(0, $total - $paid), 2, '.', ''),
                ];
            }),
            'billing_issuer' => [
                'nombre' => (string) ($issuer['nombre'] ?? ''),
                'nit' => (string) ($issuer['nit'] ?? ''),
                'direccion' => (string) ($issuer['direccion'] ?? ''),
                'telefono' => (string) ($issuer['telefono'] ?? ''),
                'correo' => (string) ($issuer['correo'] ?? ''),
            ],
        ];
    }

    private function statusLabel(string $status): string
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

    private function periodLabel(int $month, int $year): string
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
