<?php

namespace App\Http\Resources;

use App\Models\AppSetting;
use App\Models\ServiceCatalog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ServiceCatalog */
class ServiceCatalogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $basePrice = (string) $this->base_price;
        $user = $request->user();
        if ($user !== null && $user->rol === User::ROL_EMPLEADO) {
            $pct = AppSetting::getFloat(AppSetting::KEY_TECHNICIAN_CATALOG_DISCOUNT_PERCENT, 10.0);
            if ($pct > 0 && $pct < 100) {
                $adj = round((float) $basePrice * (100 - $pct) / 100, 2);
                $basePrice = number_format($adj, 2, '.', '');
            }
        }

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'name' => $this->name,
            'description' => $this->description,
            'base_price' => $basePrice,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'company' => $this->whenLoaded('company', fn () => $this->company ? [
                'id' => $this->company->id,
                'nombre' => $this->company->nombre,
            ] : null),
        ];
    }
}
