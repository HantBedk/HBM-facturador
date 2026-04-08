<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Company */
class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'factura_sigla' => $this->factura_sigla,
            'nit' => $this->nit,
            'estado' => $this->estado ?? 'activo',
            'telefono' => $this->telefono,
            'correo' => $this->correo,
            'es_cliente_puntual' => (bool) ($this->es_cliente_puntual ?? false),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
