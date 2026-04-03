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
            'amount' => $this->amount,
            'service_date' => $this->service_date?->format('Y-m-d'),
            'status' => $this->status,
            'invoiced' => isset($this->resource->invoices_count)
                ? (int) $this->resource->invoices_count > 0
                : ($this->resource->relationLoaded('invoices')
                    ? $this->resource->invoices->isNotEmpty()
                    : false),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
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
        ];
    }
}
