<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceCatalogResource;
use App\Models\ServiceCatalog;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AdminServiceCatalogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = ServiceCatalog::query()->with('company')->orderBy('name');

        if ($request->filled('status')) {
            $q->where('status', $request->string('status')->toString());
        }

        if ($request->filled('company_id')) {
            $q->where('company_id', $request->integer('company_id'));
        }

        return ServiceCatalogResource::collection(
            $q->paginate(Pagination::perPage($request, 50, 100))->withQueryString()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $companyScope = $this->resolveCatalogCompanyScope($request->input('company_id'));
        $data = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('service_catalog', 'name')->where(function ($q) use ($companyScope) {
                    if ($companyScope === null) {
                        return $q->whereNull('company_id');
                    }

                    return $q->where('company_id', $companyScope);
                }),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'base_price' => ['required', 'numeric', 'min:0.01'],
            'status' => ['sometimes', 'in:'.ServiceCatalog::STATUS_ACTIVO.','.ServiceCatalog::STATUS_INACTIVO],
        ]);

        $row = ServiceCatalog::query()->create([
            'company_id' => $companyScope,
            'name' => trim($data['name']),
            'description' => isset($data['description']) ? trim((string) $data['description']) : null,
            'base_price' => $data['base_price'],
            'status' => $data['status'] ?? ServiceCatalog::STATUS_ACTIVO,
        ]);
        $row->load('company');

        return (new ServiceCatalogResource($row))->response()->setStatusCode(201);
    }

    public function update(Request $request, ServiceCatalog $service_catalog): ServiceCatalogResource|JsonResponse
    {
        $companyScope = $request->has('company_id')
            ? $this->resolveCatalogCompanyScope($request->input('company_id'))
            : $service_catalog->company_id;

        $data = $request->validate([
            'company_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('service_catalog', 'name')
                    ->ignore($service_catalog->id)
                    ->where(function ($q) use ($companyScope) {
                        if ($companyScope === null) {
                            return $q->whereNull('company_id');
                        }

                        return $q->where('company_id', $companyScope);
                    }),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'base_price' => ['required', 'numeric', 'min:0.01'],
            'status' => ['required', 'in:'.ServiceCatalog::STATUS_ACTIVO.','.ServiceCatalog::STATUS_INACTIVO],
        ]);

        if (array_key_exists('company_id', $data)) {
            $service_catalog->company_id = $companyScope;
        }
        $service_catalog->name = trim($data['name']);
        $service_catalog->description = isset($data['description']) ? trim((string) $data['description']) : null;
        $service_catalog->base_price = $data['base_price'];
        $service_catalog->status = $data['status'];
        $service_catalog->save();
        $service_catalog->load('company');

        return new ServiceCatalogResource($service_catalog);
    }

    public function updateEstado(Request $request, ServiceCatalog $service_catalog): ServiceCatalogResource|JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:'.ServiceCatalog::STATUS_ACTIVO.','.ServiceCatalog::STATUS_INACTIVO],
        ]);

        $service_catalog->status = $data['status'];
        $service_catalog->save();

        return new ServiceCatalogResource($service_catalog);
    }

    private function resolveCatalogCompanyScope(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return (int) $raw;
    }
}
