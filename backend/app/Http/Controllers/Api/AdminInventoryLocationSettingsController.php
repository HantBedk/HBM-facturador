<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\PanelNotification;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\PanelNotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminInventoryLocationSettingsController extends Controller
{
    /** @var list<string> */
    private const DEFAULT_ASSET_TYPES = ['Equipo', 'Herramienta', 'Accesorio', 'Consumible', 'Mobiliario', 'Otro'];

    /** @var list<string> */
    private const DEFAULT_PHYSICAL_CONDITIONS = ['Nuevo', 'Bueno', 'Regular', 'Requiere mantenimiento', 'Fuera de servicio'];

    public function show(): JsonResponse
    {
        return response()->json([
            'data' => [
                'locations' => $this->resolvedLocations(),
                'asset_types' => $this->resolvedAssetTypes(),
                'physical_conditions' => $this->resolvedPhysicalConditions(),
                'empleado_inventory_venta_enabled' => AppSetting::getBool(AppSetting::KEY_EMPLEADO_INVENTORY_VENTA_ENABLED, false),
                'empleado_inventory_alquiler_enabled' => AppSetting::getBool(AppSetting::KEY_EMPLEADO_INVENTORY_ALQUILER_ENABLED, false),
            ],
            'help' => 'Ubicaciones (máx. 5), tipos de activo (máx. 25) y estados físicos (máx. 20). Permisos de técnicos y listas: cambios con contraseña.',
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'locations' => ['required', 'array', 'min:1', 'max:5'],
            'locations.*' => ['required', 'string', 'max:120'],
            'asset_types' => ['required', 'array', 'min:1', 'max:25'],
            'asset_types.*' => ['required', 'string', 'max:80'],
            'physical_conditions' => ['required', 'array', 'min:1', 'max:20'],
            'physical_conditions.*' => ['required', 'string', 'max:80'],
            'empleado_inventory_venta_enabled' => ['required', 'boolean'],
            'empleado_inventory_alquiler_enabled' => ['required', 'boolean'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        if (! Hash::check($data['current_password'], $actor->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['La contraseña no coincide con su usuario.'],
            ]);
        }

        $cleanLocations = $this->cleanStringList($data['locations'], 5);
        if ($cleanLocations === []) {
            throw ValidationException::withMessages([
                'locations' => ['Debe indicar al menos una ubicación válida.'],
            ]);
        }

        $cleanAssetTypes = $this->cleanStringList($data['asset_types'], 25);
        if ($cleanAssetTypes === []) {
            throw ValidationException::withMessages([
                'asset_types' => ['Debe indicar al menos un tipo de activo válido.'],
            ]);
        }

        $cleanPhysical = $this->cleanStringList($data['physical_conditions'], 20);
        if ($cleanPhysical === []) {
            throw ValidationException::withMessages([
                'physical_conditions' => ['Debe indicar al menos un estado físico válido.'],
            ]);
        }

        AppSetting::setJsonValue(AppSetting::KEY_INVENTORY_LOCATIONS, $cleanLocations);
        AppSetting::setJsonValue(AppSetting::KEY_INVENTORY_ASSET_TYPES, $cleanAssetTypes);
        AppSetting::setJsonValue(AppSetting::KEY_INVENTORY_PHYSICAL_CONDITIONS, $cleanPhysical);
        AppSetting::setJsonValue(AppSetting::KEY_EMPLEADO_INVENTORY_VENTA_ENABLED, (bool) $data['empleado_inventory_venta_enabled']);
        AppSetting::setJsonValue(AppSetting::KEY_EMPLEADO_INVENTORY_ALQUILER_ENABLED, (bool) $data['empleado_inventory_alquiler_enabled']);

        ActivityLogger::log(
            $actor,
            'inventario_ubicaciones_actualizadas',
            'Actualizó ubicaciones, permisos de técnicos (venta/alquiler), tipos de activo y estados físicos de inventario interno.'
        );
        app(PanelNotificationDispatcher::class)->notifyAdmins(
            PanelNotification::TYPE_INVENTORY_LOCATIONS_UPDATED,
            $actor->nombre.' actualizó configuración de inventario (ubicaciones, permisos y clasificación).',
            [
                'link' => '/admin/configuracion/inventario',
                'locations_count' => count($cleanLocations),
            ]
        );

        return response()->json([
            'message' => 'Configuración de inventario guardada correctamente.',
            'data' => [
                'locations' => $cleanLocations,
                'asset_types' => $cleanAssetTypes,
                'physical_conditions' => $cleanPhysical,
                'empleado_inventory_venta_enabled' => (bool) $data['empleado_inventory_venta_enabled'],
                'empleado_inventory_alquiler_enabled' => (bool) $data['empleado_inventory_alquiler_enabled'],
            ],
        ]);
    }

    /**
     * @param  list<mixed>  $items
     * @return list<string>
     */
    private function cleanStringList(array $items, int $max): array
    {
        return collect($items)
            ->map(fn ($item) => trim((string) $item))
            ->filter(fn ($item) => $item !== '')
            ->unique()
            ->take($max)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function resolvedLocations(): array
    {
        $defaults = ['Bodega principal', 'Taller', 'Oficina administrativa'];
        $value = AppSetting::query()->where('key', AppSetting::KEY_INVENTORY_LOCATIONS)->value('value');
        $locations = is_array($value) ? $value : $defaults;
        $clean = $this->cleanStringList($locations, 5);

        return $clean === [] ? $defaults : $clean;
    }

    /**
     * @return list<string>
     */
    private function resolvedAssetTypes(): array
    {
        $value = AppSetting::query()->where('key', AppSetting::KEY_INVENTORY_ASSET_TYPES)->value('value');
        $items = is_array($value) ? $value : self::DEFAULT_ASSET_TYPES;
        $clean = $this->cleanStringList($items, 25);

        return $clean === [] ? self::DEFAULT_ASSET_TYPES : $clean;
    }

    /**
     * @return list<string>
     */
    private function resolvedPhysicalConditions(): array
    {
        $value = AppSetting::query()->where('key', AppSetting::KEY_INVENTORY_PHYSICAL_CONDITIONS)->value('value');
        $items = is_array($value) ? $value : self::DEFAULT_PHYSICAL_CONDITIONS;
        $clean = $this->cleanStringList($items, 20);

        return $clean === [] ? self::DEFAULT_PHYSICAL_CONDITIONS : $clean;
    }
}
