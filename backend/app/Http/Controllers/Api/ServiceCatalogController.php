<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceCatalogResource;
use App\Models\ServiceCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Lista pública (autenticada) de ítems activos para registro de servicios (filtrado por empresa).
 */
class ServiceCatalogController extends Controller
{
    public function active(Request $request): AnonymousResourceCollection
    {
        $companyId = $request->integer('company_id');
        if ($companyId < 1) {
            throw ValidationException::withMessages([
                'company_id' => ['Indica la empresa para cargar el catálogo aplicable.'],
            ]);
        }

        $rows = ServiceCatalog::query()
            ->activos()
            ->forCompany($companyId)
            ->orderBy('name')
            ->get();

        return ServiceCatalogResource::collection($rows);
    }
}
