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
        $adminView = $request->user()?->isAdminEquipo() ?? false;
        $techTotalStr = $this->technicianLineTotalAttribute();

        return [
            'id' => $this->id,
            'code' => $this->code,
            'company_id' => $this->company_id,
            'user_id' => $this->user_id,
            'catalog_id' => $this->catalog_id,
            'client_name' => $this->client_name,
            'service_type' => $this->service_type,
            'description' => $this->description,
            /**
             * Admin: total facturable (cliente). Técnico: solo suma de referencia propia (sin margen/incremento de empresa).
             */
            'amount' => $adminView ? $this->amount : $techTotalStr,
            /** Solo administración: desglose técnico vs factura. */
            'technician_line_total' => $this->when($adminView, $techTotalStr),
            'service_date' => $this->service_date?->format('Y-m-d'),
            'status' => $this->status,
            'assignment_status' => $this->assignment_status,
            'assigned_by_user_id' => $this->assigned_by_user_id,
            'assigned_by' => $this->whenLoaded('assignedBy', fn () => $this->assignedBy ? [
                'id' => $this->assignedBy->id,
                'nombre' => $this->assignedBy->nombre,
            ] : null),
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
            'items' => $this->whenLoaded('items', function () use ($adminView) {
                return $this->items->map(function ($it) use ($adminView) {
                    $billed = (string) $it->amount;
                    $tech = $it->technician_line_amount !== null ? (string) $it->technician_line_amount : $billed;

                    $row = [
                        'id' => $it->id,
                        'catalog_id' => $it->catalog_id,
                        'catalog_suggestion_id' => $it->catalog_suggestion_id,
                        'label' => $it->label,
                        'line_description' => $it->line_description,
                        /** Admin: importe en factura. Técnico: solo referencia propia (sin incremento empresa). */
                        'amount' => $adminView ? $billed : $tech,
                        'sort_order' => (int) $it->sort_order,
                        'suggestion_status' => $it->relationLoaded('catalogSuggestion') && $it->catalogSuggestion
                            ? $it->catalogSuggestion->status
                            : null,
                    ];
                    if ($adminView) {
                        $row['technician_line_amount'] = $it->technician_line_amount !== null ? (string) $it->technician_line_amount : null;
                    }

                    return $row;
                })->values()->all();
            }),
        ];
    }

    private function technicianLineTotalAttribute(): string
    {
        if ($this->relationLoaded('items') && $this->items->isNotEmpty()) {
            $s = $this->items->sum(fn ($i) => (float) ($i->technician_line_amount ?? $i->amount));

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
