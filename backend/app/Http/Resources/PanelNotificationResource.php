<?php

namespace App\Http\Resources;

use App\Models\PanelNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PanelNotification */
class PanelNotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $category = PanelNotification::categoryForType($this->type);

        return [
            'id' => $this->id,
            'type' => $this->type,
            'category' => $category,
            'category_label' => match ($category) {
                'empleados' => 'Empleados',
                'servicios' => 'Servicios',
                default => 'Facturas',
            },
            'message' => $this->message,
            'read' => $this->read,
            'meta' => $this->meta,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
