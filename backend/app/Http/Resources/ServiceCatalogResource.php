<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServiceCatalog
 *
 * - `base_price` en BD = referencia orientativa (placeholder); el importe facturable lo define el empleado al cargar el servicio.
 * - `iva_percent` = IVA del estado aplicado sobre el importe de línea al armar la factura (subtotal = suma de líneas; total = subtotal + IVA).
 * - technician_discount_percent null = usar el % global de margen técnico → empresa al facturar líneas.
 */
class ServiceCatalogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $displayPrice = (string) $this->base_price;
        $user = $request->user();

        $isEmpleado = $user !== null && $user->rol === User::ROL_EMPLEADO;

        return [
            'id' => $this->id,
            'code' => 'CAT-'.$this->id,
            'name' => $this->name,
            'description' => $this->description,
            'base_price' => $displayPrice,
            'iva_percent' => (string) $this->iva_percent,
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
