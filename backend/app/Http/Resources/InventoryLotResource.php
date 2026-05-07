<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\InventoryLot */
class InventoryLotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'owner_user_id' => $this->owner_user_id,
            'tenant_company_id' => $this->tenant_company_id,
            'tenant_company' => $this->whenLoaded('tenantCompany', fn () => $this->tenantCompany ? [
                'id' => $this->tenantCompany->id,
                'nombre' => $this->tenantCompany->nombre,
                'nit' => $this->tenantCompany->nit,
            ] : null),
            'owner' => $this->whenLoaded('owner', fn () => [
                'id' => $this->owner->id,
                'nombre' => $this->owner->nombre,
                'correo' => $this->owner->correo,
            ]),
            'sku' => $this->sku,
            'name' => $this->name,
            'description' => $this->description,
            'quantity_available' => $this->quantity_available,
            'unit_price' => (string) $this->unit_price,
            'is_active' => (bool) $this->is_active,
            'allow_sale' => (bool) ($this->allow_sale ?? true),
            'allow_rental' => (bool) ($this->allow_rental ?? false),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
