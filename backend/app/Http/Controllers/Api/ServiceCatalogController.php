<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceCatalogResource;
use App\Models\ServiceCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
/**
 * Lista pública (autenticada) de ítems activos para registro de servicios (único catálogo para todas las empresas).
 */
class ServiceCatalogController extends Controller
{
    public function active(Request $request): AnonymousResourceCollection
    {
        $rows = ServiceCatalog::query()
            ->activos()
            ->orderBy('name')
            ->get();

        return ServiceCatalogResource::collection($rows);
    }
}
