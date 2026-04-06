<?php

namespace App\Http\Resources;

use App\Models\ServiceCatalog;
use App\Models\User;
use App\Support\CatalogPricing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServiceCatalog
 *
 * - base_price en BD = precio que factura (lista).
 * - Para empleados, base_price en JSON = importe de referencia del técnico (menor), salvo admin.
 * - technician_discount_percent null = usar el % global de ajuste a técnicos.
 */
class ServiceCatalogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $listPrice = (string) $this->base_price;
        $user = $request->user();

        $pEff = CatalogPricing::effectiveTechnicianDiscountPercent(
            $this->technician_discount_percent !== null ? (float) $this->technician_discount_percent : null
        );

        $displayPrice = $listPrice;
        if ($user !== null && $user->rol === User::ROL_EMPLEADO) {
            $displayPrice = CatalogPricing::technicianAmountFromListPrice((float) $this->base_price, $pEff);
        }

        $isEmpleado = $user !== null && $user->rol === User::ROL_EMPLEADO;

        return [
            'id' => $this->id,
            'code' => 'CAT-'.$this->id,
            'name' => $this->name,
            'description' => $this->description,
            'base_price' => $displayPrice,
            /** El % de margen/diferencia no se expone a empleados (solo admin en panel catálogo). */
            'technician_discount_percent' => $this->when(
                ! $isEmpleado,
                $this->technician_discount_percent !== null
                    ? (string) $this->technician_discount_percent
                    : null
            ),
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
