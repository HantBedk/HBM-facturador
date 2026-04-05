<?php

namespace App\Http\Resources;

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin Service */
class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'company_id' => $this->company_id,
            'user_id' => $this->user_id,
            'catalog_id' => $this->catalog_id,
            'client_name' => $this->client_name,
            'service_type' => $this->service_type,
            'description' => $this->description,
            /** Total facturable (suma de líneas que van a factura). */
            'amount' => $this->amount,
            /** Suma de importes de referencia del técnico por línea (menor que lo facturado si aplica margen). */
            'technician_line_total' => $this->technicianLineTotalAttribute(),
            'service_date' => $this->service_date?->format('Y-m-d'),
            'status' => $this->status,
            'invoiced' => isset($this->resource->invoices_count)
                ? (int) $this->resource->invoices_count > 0
                : ($this->resource->relationLoaded('invoices')
                    ? $this->resource->invoices->isNotEmpty()
                    : false),
            'invoices' => $this->whenLoaded('invoices', fn () => $this->invoices->map(fn ($inv) => [
                'id' => $inv->id,
                'code' => $inv->code,
            ])->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'technician_paid_at' => $this->when(
                $request->user()?->isAdminEquipo(),
                fn () => $this->technician_paid_at?->toIso8601String()
            ),
            'catalog' => $this->whenLoaded('catalog', fn () => $this->catalog ? [
                'id' => $this->catalog->id,
                'name' => $this->catalog->name,
            ] : null),
            'company' => $this->whenLoaded('company', fn () => [
                'id' => $this->company->id,
                'nombre' => $this->company->nombre,
                'nit' => $this->company->nit,
            ]),
            'empleado' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'nombre' => $this->user->nombre,
                'correo' => $this->user->correo,
            ]),
            'photos' => $this->whenLoaded('photos', fn () => $this->photos->map(fn ($p) => [
                'id' => $p->id,
                'url' => Storage::disk('public')->url($p->path),
                'sort_order' => $p->sort_order,
            ])->values()->all()),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($it) => [
                'id' => $it->id,
                'catalog_id' => $it->catalog_id,
                'catalog_suggestion_id' => $it->catalog_suggestion_id,
                'label' => $it->label,
                'line_description' => $it->line_description,
                /** Importe en factura (línea). */
                'amount' => (string) $it->amount,
                /** Importe de referencia del técnico (lo que “ve” o ingresó). */
                'technician_line_amount' => $it->technician_line_amount !== null ? (string) $it->technician_line_amount : null,
                'sort_order' => (int) $it->sort_order,
                'suggestion_status' => $it->relationLoaded('catalogSuggestion') && $it->catalogSuggestion
                    ? $it->catalogSuggestion->status
                    : null,
            ])->values()->all()),
        ];
    }

    private function technicianLineTotalAttribute(): string
    {
        if ($this->relationLoaded('items') && $this->items->isNotEmpty()) {
            $s = $this->items->sum(fn ($i) => (float) $i->technician_line_amount);

            return number_format($s, 2, '.', '');
        }

        $sum = $this->resource->getAttribute('items_sum_technician_line_amount');
        $cnt = (int) $this->resource->getAttribute('items_count');
        if ($sum !== null && $cnt > 0) {
            return number_format((float) $sum, 2, '.', '');
        }

        return number_format((float) $this->amount, 2, '.', '');
    }
}
