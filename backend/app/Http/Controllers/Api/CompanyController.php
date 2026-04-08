<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CompanyController extends Controller
{
    /**
     * Empresas activas para selección en servicios (empleados y administradores).
     */
    public function index(): AnonymousResourceCollection
    {
        return CompanyResource::collection(
            Company::query()
                ->activas()
                ->where('es_cliente_puntual', false)
                ->orderBy('nombre')
                ->get()
        );
    }
}
