<?php

namespace App\Http\Resources;

use App\Models\CompanyRecurringService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CompanyRecurringService */
class CompanyRecurringServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'catalog_id' => $this->catalog_id,
            'service_type' => $this->service_type,
            'description' => $this->description,
            'amount' => (string) $this->amount,
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'catalog' => $this->whenLoaded('catalog', fn () => $this->catalog ? [
                'id' => $this->catalog->id,
                'name' => $this->catalog->name,
            ] : null),
        ];
    }
}
