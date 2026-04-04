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
        $meta = $this->meta ?? [];
        $link = PanelNotification::resolveAdminPanelLink($this->type, $meta);
        if ($link !== null) {
            $meta['link'] = $link;
        }

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
            'meta' => $meta,
            'link' => $link,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
