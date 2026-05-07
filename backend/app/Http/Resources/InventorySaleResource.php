<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\InventorySale */
class InventorySaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sold_by_user_id' => $this->sold_by_user_id,
            'tenant_company_id' => $this->tenant_company_id,
            'tenant_company' => $this->whenLoaded('tenantCompany', fn () => $this->tenantCompany ? [
                'id' => $this->tenantCompany->id,
                'nombre' => $this->tenantCompany->nombre,
                'nit' => $this->tenantCompany->nit,
            ] : null),
            'sold_by' => $this->whenLoaded('soldBy', fn () => [
                'id' => $this->soldBy->id,
                'nombre' => $this->soldBy->nombre,
                'correo' => $this->soldBy->correo,
            ]),
            'notes' => $this->notes,
            'total_amount' => (string) $this->total_amount,
            'lines' => $this->whenLoaded('lines', function () {
                return $this->lines->map(function ($line) {
                    return [
                        'id' => $line->id,
                        'inventory_lot_id' => $line->inventory_lot_id,
                        'owner_user_id' => $line->owner_user_id,
                        'tenant_company_id' => $line->tenant_company_id,
                        'owner' => $line->relationLoaded('owner') && $line->owner ? [
                            'id' => $line->owner->id,
                            'nombre' => $line->owner->nombre,
                        ] : null,
                        'quantity' => $line->quantity,
                        'unit_price' => (string) $line->unit_price,
                        'line_total' => (string) $line->line_total,
                        'lot' => $line->relationLoaded('lot') && $line->lot ? [
                            'id' => $line->lot->id,
                            'name' => $line->lot->name,
                            'sku' => $line->lot->sku,
                        ] : null,
                    ];
                })->values()->all();
            }),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
