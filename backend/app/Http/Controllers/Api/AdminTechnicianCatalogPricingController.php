<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\User;
use App\Support\CatalogPricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Márgenes globales: servicio (catálogo / «Otro»), venta de inventario, alquiler de inventario.
 * Pisos mínimos por tipo: configurables; el margen aplicado no puede quedar por debajo del piso.
 * factura (empresa) = referencia técnica ÷ ((100−p)/100).
 */
class AdminTechnicianCatalogPricingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $service = AppSetting::getFloat(AppSetting::KEY_TECHNICIAN_CATALOG_DISCOUNT_PERCENT, 10.0);
        $sale = AppSetting::getFloat(AppSetting::KEY_TECHNICIAN_INVENTORY_SALE_DISCOUNT_PERCENT, 10.0);
        $rental = AppSetting::getFloat(AppSetting::KEY_TECHNICIAN_INVENTORY_RENTAL_DISCOUNT_PERCENT, 10.0);

        $floorService = CatalogPricing::floorServiceDiscountPercent();
        $floorSale = CatalogPricing::floorInventorySaleDiscountPercent();
        $floorRental = CatalogPricing::floorInventoryRentalDiscountPercent();

        return response()->json([
            'data' => [
                'technician_service_discount_percent' => $service,
                'technician_catalog_discount_percent' => $service,
                'technician_inventory_sale_discount_percent' => $sale,
                'technician_inventory_rental_discount_percent' => $rental,
                'technician_service_margin_floor_percent' => $floorService,
                'technician_inventory_sale_margin_floor_percent' => $floorSale,
                'technician_inventory_rental_margin_floor_percent' => $floorRental,
                'margin_constraints' => CatalogPricing::globalMarginFieldConstraints(),
                'can_edit_margin_floors' => $request->user()?->rol === User::ROL_SUPER_ADMIN,
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $max = CatalogPricing::MAX_GLOBAL_DISCOUNT_PERCENT;
        $canEditFloors = $request->user()?->rol === User::ROL_SUPER_ADMIN;

        $rules = [
            'technician_service_discount_percent' => ['required', 'numeric', 'min:0', 'max:'.$max],
            'technician_catalog_discount_percent' => ['sometimes', 'numeric', 'min:0', 'max:'.$max],
            'technician_inventory_sale_discount_percent' => ['required', 'numeric', 'min:0', 'max:'.$max],
            'technician_inventory_rental_discount_percent' => ['required', 'numeric', 'min:0', 'max:'.$max],
        ];
        if ($canEditFloors) {
            $rules['technician_service_margin_floor_percent'] = ['required', 'numeric', 'min:0', 'max:'.$max];
            $rules['technician_inventory_sale_margin_floor_percent'] = ['required', 'numeric', 'min:0', 'max:'.$max];
            $rules['technician_inventory_rental_margin_floor_percent'] = ['required', 'numeric', 'min:0', 'max:'.$max];
        }

        $data = $request->validate($rules);

        $floorService = CatalogPricing::floorServiceDiscountPercent();
        $floorSale = CatalogPricing::floorInventorySaleDiscountPercent();
        $floorRental = CatalogPricing::floorInventoryRentalDiscountPercent();

        if ($canEditFloors) {
            $floorService = round((float) $data['technician_service_margin_floor_percent'], 2);
            $floorSale = round((float) $data['technician_inventory_sale_margin_floor_percent'], 2);
            $floorRental = round((float) $data['technician_inventory_rental_margin_floor_percent'], 2);
        } elseif ($this->requestAttemptsFloorChange($request, $floorService, $floorSale, $floorRental)) {
            return response()->json([
                'message' => 'Solo un super administrador puede modificar los pisos mínimos de los márgenes globales.',
            ], Response::HTTP_FORBIDDEN);
        }

        $serviceRaw = $data['technician_service_discount_percent'] ?? $data['technician_catalog_discount_percent'] ?? null;
        if ($serviceRaw === null) {
            $serviceRaw = AppSetting::getFloat(AppSetting::KEY_TECHNICIAN_CATALOG_DISCOUNT_PERCENT, 10.0);
        }
        $service = max($floorService, min($max, round((float) $serviceRaw, 2)));
        $sale = max($floorSale, min($max, round((float) $data['technician_inventory_sale_discount_percent'], 2)));
        $rental = max($floorRental, min($max, round((float) $data['technician_inventory_rental_discount_percent'], 2)));

        AppSetting::setValue(AppSetting::KEY_TECHNICIAN_CATALOG_DISCOUNT_FLOOR_PERCENT, (string) $floorService);
        AppSetting::setValue(AppSetting::KEY_TECHNICIAN_INVENTORY_SALE_DISCOUNT_FLOOR_PERCENT, (string) $floorSale);
        AppSetting::setValue(AppSetting::KEY_TECHNICIAN_INVENTORY_RENTAL_DISCOUNT_FLOOR_PERCENT, (string) $floorRental);

        AppSetting::setValue(AppSetting::KEY_TECHNICIAN_CATALOG_DISCOUNT_PERCENT, (string) $service);
        AppSetting::setValue(AppSetting::KEY_TECHNICIAN_INVENTORY_SALE_DISCOUNT_PERCENT, (string) $sale);
        AppSetting::setValue(AppSetting::KEY_TECHNICIAN_INVENTORY_RENTAL_DISCOUNT_PERCENT, (string) $rental);

        return response()->json([
            'data' => [
                'technician_service_discount_percent' => $service,
                'technician_catalog_discount_percent' => $service,
                'technician_inventory_sale_discount_percent' => $sale,
                'technician_inventory_rental_discount_percent' => $rental,
                'technician_service_margin_floor_percent' => $floorService,
                'technician_inventory_sale_margin_floor_percent' => $floorSale,
                'technician_inventory_rental_margin_floor_percent' => $floorRental,
                'margin_constraints' => CatalogPricing::globalMarginFieldConstraints(),
                'can_edit_margin_floors' => $canEditFloors,
            ],
        ]);
    }

    private function requestAttemptsFloorChange(Request $request, float $floorService, float $floorSale, float $floorRental): bool
    {
        $checks = [
            'technician_service_margin_floor_percent' => $floorService,
            'technician_inventory_sale_margin_floor_percent' => $floorSale,
            'technician_inventory_rental_margin_floor_percent' => $floorRental,
        ];
        foreach ($checks as $key => $current) {
            if (! $request->has($key)) {
                continue;
            }
            if (abs(round((float) $request->input($key), 2) - $current) > 0.009) {
                return true;
            }
        }

        return false;
    }
}
