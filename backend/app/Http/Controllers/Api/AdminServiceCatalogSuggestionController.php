<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceCatalogSuggestionResource;
use App\Models\ServiceCatalog;
use App\Models\ServiceCatalogSuggestion;
use App\Models\ServiceItem;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminServiceCatalogSuggestionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = ServiceCatalogSuggestion::query()
            ->with(['user'])
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $q->where('status', $request->string('status')->toString());
        } elseif ($request->boolean('pendientes')) {
            $q->where('status', ServiceCatalogSuggestion::STATUS_PENDING);
        }

        if ($request->filled('company_id')) {
            $q->where('company_id', $request->integer('company_id'));
        }

        return ServiceCatalogSuggestionResource::collection(
            $q->paginate(Pagination::perPage($request, 50, 100))->withQueryString()
        );
    }

    public function approve(Request $request, ServiceCatalogSuggestion $service_catalog_suggestion): JsonResponse
    {
        if ($service_catalog_suggestion->status !== ServiceCatalogSuggestion::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => ['Esta propuesta ya fue resuelta.'],
            ]);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'base_price' => ['sometimes', 'numeric', 'min:0.01'],
        ]);

        $name = isset($data['name']) ? trim($data['name']) : $service_catalog_suggestion->name;
        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => ['El nombre es obligatorio.'],
            ]);
        }
        $description = array_key_exists('description', $data)
            ? ($data['description'] !== null ? trim((string) $data['description']) : null)
            : $service_catalog_suggestion->description;
        $basePrice = isset($data['base_price'])
            ? $data['base_price']
            : (string) $service_catalog_suggestion->suggested_price;

        $dup = ServiceCatalog::query()
            ->where('name', $name)
            ->whereNull('company_id')
            ->exists();
        if ($dup) {
            throw ValidationException::withMessages([
                'name' => ['Ya existe un ítem global con ese nombre en el catálogo.'],
            ]);
        }

        DB::transaction(function () use ($service_catalog_suggestion, $name, $description, $basePrice) {
            $cat = ServiceCatalog::query()->create([
                'company_id' => null,
                'name' => $name,
                'description' => $description,
                'base_price' => $basePrice,
                'status' => ServiceCatalog::STATUS_ACTIVO,
            ]);

            $service_catalog_suggestion->status = ServiceCatalogSuggestion::STATUS_APPROVED;
            $service_catalog_suggestion->resolved_catalog_id = $cat->id;
            $service_catalog_suggestion->save();

            ServiceItem::query()
                ->where('catalog_suggestion_id', $service_catalog_suggestion->id)
                ->update([
                    'catalog_id' => $cat->id,
                    'catalog_suggestion_id' => null,
                    'label' => $name,
                ]);
        });

        $service_catalog_suggestion->refresh()->load(['user']);

        return (new ServiceCatalogSuggestionResource($service_catalog_suggestion))->response();
    }

    public function reject(ServiceCatalogSuggestion $service_catalog_suggestion): JsonResponse
    {
        if ($service_catalog_suggestion->status !== ServiceCatalogSuggestion::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => ['Esta propuesta ya fue resuelta.'],
            ]);
        }

        $service_catalog_suggestion->status = ServiceCatalogSuggestion::STATUS_REJECTED;
        $service_catalog_suggestion->save();
        $service_catalog_suggestion->load(['user']);

        return (new ServiceCatalogSuggestionResource($service_catalog_suggestion))->response();
    }
}
