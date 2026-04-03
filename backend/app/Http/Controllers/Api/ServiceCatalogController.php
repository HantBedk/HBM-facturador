<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceCatalogResource;
use App\Models\ServiceCatalog;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Lista pública (autenticada) de ítems activos para autocompletado en registro de servicios.
 */
class ServiceCatalogController extends Controller
{
    public function active(): AnonymousResourceCollection
    {
        $rows = ServiceCatalog::query()
            ->activos()
            ->orderBy('name')
            ->get();

        return ServiceCatalogResource::collection($rows);
    }
}
