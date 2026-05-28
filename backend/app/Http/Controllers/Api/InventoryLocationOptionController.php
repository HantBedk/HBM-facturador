<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\JsonResponse;

class InventoryLocationOptionController extends Controller
{
    /** @var list<string> */
    private const DEFAULT_LOCATIONS = ['Bodega principal', 'Taller', 'Oficina administrativa'];

    /** @var list<string> */
    private const DEFAULT_ASSET_TYPES = ['Equipo', 'Herramienta', 'Accesorio', 'Consumible', 'Mobiliario', 'Otro'];

    /** @var list<string> */
    private const DEFAULT_PHYSICAL_CONDITIONS = ['Nuevo', 'Bueno', 'Regular', 'Requiere mantenimiento', 'Fuera de servicio'];

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'locations' => $this->resolvedList(AppSetting::KEY_INVENTORY_LOCATIONS, self::DEFAULT_LOCATIONS, 5),
                'asset_types' => $this->resolvedList(AppSetting::KEY_INVENTORY_ASSET_TYPES, self::DEFAULT_ASSET_TYPES, 25),
                'physical_conditions' => $this->resolvedList(
                    AppSetting::KEY_INVENTORY_PHYSICAL_CONDITIONS,
                    self::DEFAULT_PHYSICAL_CONDITIONS,
                    20
                ),
            ],
        ]);
    }

    /**
     * @param  list<string>  $defaults
     * @return list<string>
     */
    private function resolvedList(string $key, array $defaults, int $max): array
    {
        $value = AppSetting::query()->where('key', $key)->value('value');
        $raw = is_array($value) ? $value : $defaults;
        $clean = collect($raw)
            ->map(fn ($item) => trim((string) $item))
            ->filter(fn ($item) => $item !== '')
            ->unique()
            ->take($max)
            ->values()
            ->all();

        return $clean === [] ? $defaults : $clean;
    }
}
