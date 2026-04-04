<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceCatalogResource;
use App\Models\ServiceCatalog;
use App\Services\ServiceCatalogSpreadsheetImporter;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminServiceCatalogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = ServiceCatalog::query()->with('company');

        $sortField = $request->query('sort', 'name');
        $sortField = is_string($sortField) ? $sortField : 'name';
        if (! in_array($sortField, ['name', 'base_price'], true)) {
            $sortField = 'name';
        }
        $direction = strtolower((string) $request->query('direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        $q->orderBy($sortField, $direction)->orderBy('id');

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

    public function destroy(ServiceCatalog $service_catalog): JsonResponse
    {
        $service_catalog->delete();

        return response()->json(['message' => 'Ítem del catálogo eliminado.']);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct', 'exists:service_catalog,id'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['ids'])));
        $deleted = 0;

        DB::transaction(function () use ($ids, &$deleted) {
            $deleted = ServiceCatalog::query()->whereIn('id', $ids)->delete();
        });

        return response()->json([
            'message' => $deleted === 1
                ? 'Se eliminó 1 ítem del catálogo.'
                : "Se eliminaron {$deleted} ítems del catálogo.",
            'deleted' => $deleted,
        ]);
    }

    /**
     * Importación masiva desde Excel (.xlsx, .xls) o CSV (UTF-8), primera hoja.
     */
    public function import(Request $request, ServiceCatalogSpreadsheetImporter $importer): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:xlsx,xls,csv,txt'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
        ]);

        $companyScope = $this->resolveCatalogCompanyScope($data['company_id'] ?? null);
        $parsed = $importer->parse($request->file('file'));

        $imported = 0;
        $skippedDuplicates = 0;
        $issues = [];

        foreach ($parsed as $row) {
            $line = $row['line'];
            $name = $row['name'];

            if ($this->catalogNameExistsInScope($name, $companyScope)) {
                $skippedDuplicates++;
                $issues[] = [
                    'row' => $line,
                    'code' => 'duplicate',
                    'message' => 'Ya existe un ítem con el mismo nombre en este ámbito (global o empresa).',
                ];

                continue;
            }

            try {
                ServiceCatalog::query()->create([
                    'company_id' => $companyScope,
                    'name' => $name,
                    'description' => $row['description'],
                    'base_price' => $row['base_price'],
                    'status' => ServiceCatalog::STATUS_ACTIVO,
                ]);
                $imported++;
            } catch (\Throwable $e) {
                $issues[] = [
                    'row' => $line,
                    'code' => 'error',
                    'message' => 'No se pudo crear el registro.',
                ];
                report($e);
            }
        }

        $msgParts = [];
        if ($imported > 0) {
            $msgParts[] = $imported === 1 ? 'Se importó 1 ítem.' : "Se importaron {$imported} ítems.";
        }
        if ($skippedDuplicates > 0) {
            $msgParts[] = $skippedDuplicates === 1
                ? '1 fila omitida por nombre duplicado.'
                : "{$skippedDuplicates} filas omitidas por nombre duplicado.";
        }
        $errorCount = count(array_filter($issues, fn ($i) => ($i['code'] ?? '') === 'error'));
        if ($errorCount > 0) {
            $msgParts[] = $errorCount === 1 ? '1 fila falló al guardar.' : "{$errorCount} filas fallaron al guardar.";
        }
        if ($imported === 0 && $skippedDuplicates === 0 && $issues === []) {
            $msgParts[] = 'No se importó ningún ítem.';
        }

        return response()->json([
            'message' => implode(' ', $msgParts),
            'imported' => $imported,
            'skipped_duplicates' => $skippedDuplicates,
            'issues' => $issues,
        ]);
    }

    private function catalogNameExistsInScope(string $name, ?int $companyScope): bool
    {
        $lower = mb_strtolower(trim($name));

        return ServiceCatalog::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [$lower])
            ->where(function ($q) use ($companyScope) {
                if ($companyScope === null) {
                    $q->whereNull('company_id');
                } else {
                    $q->where('company_id', $companyScope);
                }
            })
            ->exists();
    }

    private function resolveCatalogCompanyScope(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return (int) $raw;
    }
}
