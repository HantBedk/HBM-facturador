<?php

namespace App\Http\Resources;

use App\Models\ServiceCatalogSuggestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ServiceCatalogSuggestion */
class ServiceCatalogSuggestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'name' => $this->name,
            'description' => $this->description,
            'suggested_price' => (string) $this->suggested_price,
            'status' => $this->status,
            'resolved_catalog_id' => $this->resolved_catalog_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'nombre' => $this->user->nombre,
            ]),
        ];
    }
}
