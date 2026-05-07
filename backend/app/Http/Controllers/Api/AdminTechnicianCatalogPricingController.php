<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Support\CatalogPricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Márgenes globales: servicio (catálogo / «Otro»), venta de inventario, alquiler de inventario.
 * Pisos mínimos por tipo: configurables; el margen aplicado no puede quedar por debajo del piso.
 * factura (empresa) = referencia técnica ÷ ((100−p)/100).
 */
class AdminTechnicianCatalogPricingController extends Controller
{
    public function show(): JsonResponse
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
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $max = CatalogPricing::MAX_GLOBAL_DISCOUNT_PERCENT;

        $data = $request->validate([
            'technician_service_discount_percent' => ['required', 'numeric', 'min:0', 'max:'.$max],
            'technician_catalog_discount_percent' => ['sometimes', 'numeric', 'min:0', 'max:'.$max],
            'technician_inventory_sale_discount_percent' => ['required', 'numeric', 'min:0', 'max:'.$max],
            'technician_inventory_rental_discount_percent' => ['required', 'numeric', 'min:0', 'max:'.$max],
            'technician_service_margin_floor_percent' => ['required', 'numeric', 'min:0', 'max:'.$max],
            'technician_inventory_sale_margin_floor_percent' => ['required', 'numeric', 'min:0', 'max:'.$max],
            'technician_inventory_rental_margin_floor_percent' => ['required', 'numeric', 'min:0', 'max:'.$max],
        ]);

        $floorService = round((float) $data['technician_service_margin_floor_percent'], 2);
        $floorSale = round((float) $data['technician_inventory_sale_margin_floor_percent'], 2);
        $floorRental = round((float) $data['technician_inventory_rental_margin_floor_percent'], 2);

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
            ],
        ]);
    }
}
