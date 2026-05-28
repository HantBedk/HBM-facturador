<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\InventoryRental */
class InventoryRentalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'created_by_user_id' => $this->created_by_user_id,
            'tenant_company_id' => $this->tenant_company_id,
            'tenant_company' => $this->whenLoaded('tenantCompany', fn () => $this->tenantCompany ? [
                'id' => $this->tenantCompany->id,
                'nombre' => $this->tenantCompany->nombre,
                'nit' => $this->tenantCompany->nit,
            ] : null),
            'created_by' => $this->whenLoaded('createdBy', fn () => [
                'id' => $this->createdBy->id,
                'nombre' => $this->createdBy->nombre,
                'correo' => $this->createdBy->correo,
            ]),
            'status' => $this->status,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'notes' => $this->notes,
            'started_at' => $this->started_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
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
                        'rental_days' => (int) ($line->rental_days ?? 1),
                        'unit_price' => (string) $line->unit_price,
                        'line_total' => (string) $line->line_total,
                        'returned_at' => $line->returned_at?->toIso8601String(),
                        'lot' => $line->relationLoaded('lot') && $line->lot ? [
                            'id' => $line->lot->id,
                            'name' => $line->lot->name,
                            'internal_code' => $line->lot->internal_code,
                        ] : null,
                    ];
                })->values()->all();
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
