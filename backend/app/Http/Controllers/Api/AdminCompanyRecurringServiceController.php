<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyRecurringServiceResource;
use App\Models\Company;
use App\Models\CompanyRecurringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminCompanyRecurringServiceController extends Controller
{
    public function index(Company $company): AnonymousResourceCollection
    {
        $rows = $company->recurringServices()->with('catalog:id,name')->get();

        return CompanyRecurringServiceResource::collection($rows);
    }

    public function store(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate([
            'catalog_id' => ['nullable', 'integer', 'exists:service_catalog,id'],
            'billing_kind' => ['required', 'string', 'in:venta,servicio,alquiler'],
            'service_type' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $sortOrder = $this->nextRecurringSortOrder($company);

        $row = $company->recurringServices()->create([
            'catalog_id' => array_key_exists('catalog_id', $data) && $data['catalog_id'] !== null
                ? (int) $data['catalog_id']
                : null,
            'billing_kind' => (string) $data['billing_kind'],
            'service_type' => isset($data['service_type']) && $data['service_type'] !== ''
                ? trim($data['service_type'])
                : null,
            'description' => isset($data['description']) && $data['description'] !== ''
                ? trim($data['description'])
                : null,
            'amount' => $data['amount'],
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $sortOrder,
        ]);

        $row->load('catalog:id,name');

        return (new CompanyRecurringServiceResource($row))->response()->setStatusCode(201);
    }

    public function update(Request $request, Company $company, CompanyRecurringService $recurring_service): CompanyRecurringServiceResource
    {
        $this->assertSameCompany($company, $recurring_service);

        $data = $request->validate([
            'catalog_id' => ['sometimes', 'nullable', 'integer', 'exists:service_catalog,id'],
            'billing_kind' => ['sometimes', 'required', 'string', 'in:venta,servicio,alquiler'],
            'service_type' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999999'],
        ]);

        if (array_key_exists('catalog_id', $data)) {
            $recurring_service->catalog_id = $data['catalog_id'] !== null ? (int) $data['catalog_id'] : null;
        }
        if (array_key_exists('billing_kind', $data)) {
            $recurring_service->billing_kind = (string) $data['billing_kind'];
        }
        if (array_key_exists('service_type', $data)) {
            $recurring_service->service_type = $data['service_type'] !== null && trim($data['service_type']) !== ''
                ? trim($data['service_type'])
                : null;
        }
        if (array_key_exists('description', $data)) {
            $recurring_service->description = $data['description'] !== null && trim($data['description']) !== ''
                ? trim($data['description'])
                : null;
        }
        if (array_key_exists('amount', $data)) {
            $recurring_service->amount = $data['amount'];
        }
        if (array_key_exists('is_active', $data)) {
            $recurring_service->is_active = (bool) $data['is_active'];
        }
        if (array_key_exists('sort_order', $data)) {
            $recurring_service->sort_order = (int) $data['sort_order'];
        }

        $recurring_service->save();
        $recurring_service->load('catalog:id,name');

        return new CompanyRecurringServiceResource($recurring_service);
    }

    public function destroy(Company $company, CompanyRecurringService $recurring_service): JsonResponse
    {
        $this->assertSameCompany($company, $recurring_service);
        $recurring_service->delete();

        return response()->json(null, 204);
    }

    private function assertSameCompany(Company $company, CompanyRecurringService $row): void
    {
        if ((int) $row->company_id !== (int) $company->id) {
            abort(404);
        }
    }

    /** Orden de alta: 0, 1, 2… según el máximo actual de plantillas de la empresa. */
    private function nextRecurringSortOrder(Company $company): int
    {
        $max = CompanyRecurringService::query()
            ->where('company_id', $company->id)
            ->max('sort_order');

        return $max === null ? 0 : (int) $max + 1;
    }
}
