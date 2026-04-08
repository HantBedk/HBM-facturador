<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $base = [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'correo' => $this->correo,
            'rol' => $this->rol,
            'estado' => $this->estado,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if ($this->rol === User::ROL_EMPLEADO) {
            $base['correo_solicitado'] = $this->correo_solicitado;
            $base['correo_solicitado_at'] = $this->correo_solicitado_at?->toIso8601String();

            /** @var array<int, array{technician_debt_pending_total: string, technician_debt_pending_services: int}>|null $debtMap */
            $debtMap = $request->attributes->get('admin_users_technician_debt');
            if (is_array($debtMap) && isset($debtMap[(int) $this->id])) {
                $d = $debtMap[(int) $this->id];
                $base['technician_debt_pending_total'] = $d['technician_debt_pending_total'];
                $base['technician_debt_pending_services'] = $d['technician_debt_pending_services'];
            }
        }

        return $base;
    }
}
