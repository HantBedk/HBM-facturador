<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Porcentaje de margen aplicado al pasar del importe declarado por el técnico al importe facturable a la empresa (líneas de catálogo y «Otro»).
 */
class AdminTechnicianCatalogPricingController extends Controller
{
    public function show(): JsonResponse
    {
        $p = AppSetting::getFloat(AppSetting::KEY_TECHNICIAN_CATALOG_DISCOUNT_PERCENT, 10.0);

        return response()->json([
            'data' => [
                'technician_catalog_discount_percent' => $p,
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'technician_catalog_discount_percent' => ['required', 'numeric', 'min:0', 'max:95'],
        ]);

        $v = round((float) $data['technician_catalog_discount_percent'], 2);
        AppSetting::setValue(AppSetting::KEY_TECHNICIAN_CATALOG_DISCOUNT_PERCENT, (string) $v);

        return response()->json([
            'data' => [
                'technician_catalog_discount_percent' => $v,
            ],
        ]);
    }
}
