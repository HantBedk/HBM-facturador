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
            'serial_number' => $this->serial_number,
            'mac_address' => $this->mac_address,
            'name' => $this->name,
            'description' => $this->description,
            'quantity_available' => $this->quantity_available,
            /** Unidades actualmente en alquiler activo (no devueltas). */
            'units_on_rent' => (int) ($this->resource->getAttribute('units_on_rent') ?? 0),
            'quantity_total' => (int) $this->quantity_available + (int) ($this->resource->getAttribute('units_on_rent') ?? 0),
            'unit_price' => (string) $this->unit_price,
            'is_active' => (bool) $this->is_active,
            'lifecycle_status' => $this->lifecycle_status ?: ((bool) $this->is_active ? 'activo' : 'baja'),
            'lifecycle_status_changed_at' => $this->lifecycle_status_changed_at?->toIso8601String(),
            'repair_reason' => $this->repair_reason,
            'repair_resolution' => $this->repair_resolution,
            'decommission_reason' => $this->decommission_reason,
            'decommissioned_at' => $this->decommissioned_at?->toIso8601String(),
            'allow_sale' => (bool) ($this->allow_sale ?? true),
            'allow_rental' => (bool) ($this->allow_rental ?? false),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
