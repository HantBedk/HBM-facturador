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

class AdminServiceCatalogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = ServiceCatalog::query();

        $sortField = $request->query('sort', 'name');
        $sortField = is_string($sortField) ? $sortField : 'name';
        if (! in_array($sortField, ['name', 'base_price', 'iva_percent', 'code', 'status'], true)) {
            $sortField = 'name';
        }
        $direction = strtolower((string) $request->query('direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        $dbColumn = $sortField === 'code' ? 'id' : $sortField;
        $q->orderBy($dbColumn, $direction);
        if ($dbColumn !== 'id') {
            $q->orderBy('id', $direction);
        }

        if ($request->filled('status')) {
            $q->where('status', $request->string('status')->toString());
        }

        return ServiceCatalogResource::collection(
            $q->paginate(Pagination::perPage($request, 50, 100))->withQueryString()
        );
    }

    public function show(ServiceCatalog $service_catalog): ServiceCatalogResource
    {
        return new ServiceCatalogResource($service_catalog);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($this->catalogNameExistsGlobally(trim((string) $value))) {
                        $fail('Ya existe un ítem con este nombre en el catálogo.');
                    }
                },
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'base_price' => ['required', 'numeric', 'min:0.01'],
            'iva_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'technician_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:99.99'],
            'status' => ['sometimes', 'in:'.ServiceCatalog::STATUS_ACTIVO.','.ServiceCatalog::STATUS_INACTIVO],
        ]);

        $row = ServiceCatalog::query()->create([
            'name' => trim($data['name']),
            'description' => isset($data['description']) ? trim((string) $data['description']) : null,
            'base_price' => $data['base_price'],
            'iva_percent' => $data['iva_percent'] ?? 0,
            'technician_discount_percent' => $data['technician_discount_percent'] ?? null,
            'status' => $data['status'] ?? ServiceCatalog::STATUS_ACTIVO,
        ]);

        return (new ServiceCatalogResource($row))->response()->setStatusCode(201);
    }

    public function update(Request $request, ServiceCatalog $service_catalog): ServiceCatalogResource|JsonResponse
    {
        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail) use ($service_catalog) {
                    if ($this->catalogNameExistsGlobally(trim((string) $value), $service_catalog->id)) {
                        $fail('Ya existe un ítem con este nombre en el catálogo.');
                    }
                },
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'base_price' => ['required', 'numeric', 'min:0.01'],
            'iva_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'technician_discount_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99.99'],
            'status' => ['required', 'in:'.ServiceCatalog::STATUS_ACTIVO.','.ServiceCatalog::STATUS_INACTIVO],
        ]);

        $service_catalog->name = trim($data['name']);
        $service_catalog->description = isset($data['description']) ? trim((string) $data['description']) : null;
        $service_catalog->base_price = $data['base_price'];
        if (array_key_exists('iva_percent', $data)) {
            $service_catalog->iva_percent = $data['iva_percent'] ?? 0;
        }
        if (array_key_exists('technician_discount_percent', $data)) {
            $service_catalog->technician_discount_percent = $data['technician_discount_percent'];
        }
        $service_catalog->status = $data['status'];
        $service_catalog->save();

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
        // `mimes:` rechaza CSV típico de Excel en Windows (p. ej. application/vnd.ms-excel).
        $request->validate([
            'file' => [
                'required',
                'file',
                'max:5120',
                'extensions:csv,txt,xlsx,xls,xlsm,xltx,xltm',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! $value instanceof \Illuminate\Http\UploadedFile) {
                        return;
                    }
                    $size = $value->getSize();
                    if ($size === false || $size < 1) {
                        $fail('El archivo está vacío o no se recibió el contenido. “Copiar como cURL” desde Chrome suele omitir el cuerpo del archivo: suba el CSV desde la pantalla de catálogo o use curl -F "file=@/ruta/catalogo.csv" -H "Authorization: Bearer SU_TOKEN" la URL /api/admin/service-catalog/import de su entorno.');
                    }
                },
            ],
        ]);

        $parsed = $importer->parse($request->file('file'));

        $imported = 0;
        $skippedDuplicates = 0;
        $issues = [];

        foreach ($parsed as $row) {
            $line = $row['line'];
            $name = $row['name'];

            if ($this->catalogNameExistsGlobally($name)) {
                $skippedDuplicates++;
                $issues[] = [
                    'row' => $line,
                    'code' => 'duplicate',
                    'message' => 'Ya existe un ítem con el mismo nombre en el catálogo.',
                ];

                continue;
            }

            try {
                ServiceCatalog::query()->create([
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

    private function catalogNameExistsGlobally(string $name, ?int $exceptId = null): bool
    {
        $lower = mb_strtolower(trim($name));
        $q = ServiceCatalog::query()->whereRaw('LOWER(TRIM(name)) = ?', [$lower]);
        if ($exceptId !== null) {
            $q->where('id', '!=', $exceptId);
        }

        return $q->exists();
    }
}
