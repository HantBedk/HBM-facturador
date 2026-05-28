<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessServiceImport;
use App\Services\ServiceRegistrySpreadsheetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminServiceRegistrySpreadsheetController extends Controller
{
    /**
     * Plantilla con las mismas columnas que la exportación del listado.
     */
    public function template(): StreamedResponse
    {
        $filename = 'plantilla-servicios.csv';

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ServiceRegistrySpreadsheetService::EXPORT_HEADERS, ';');
            fputcsv($out, [
                '2026-05-15',
                'Empresa ejemplo S.A.S.',
                '900123456-1',
                '',
                'servicio',
                '',
                '',
                '',
                'Instalación CCTV',
                'Instalación de cámaras en bodega principal con prueba de señal.',
                'Juan Técnico',
                'Contacto en obra',
                '250000.00',
                'activo',
            ], ';');
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Recibe el archivo, lo guarda en storage y despacha el job de importación.
     * Retorna 202 con { job_id } para que el cliente haga polling.
     */
    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => [
                'required',
                'file',
                'max:10240',
                'extensions:csv,txt,xlsx,xls,xlsm,xltx,xltm',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! $value instanceof \Illuminate\Http\UploadedFile) {
                        return;
                    }
                    $size = $value->getSize();
                    if ($size === false || $size < 1) {
                        $fail('El archivo está vacío o no se recibió el contenido.');
                    }
                },
            ],
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        $dryRun = filter_var($data['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $jobId = (string) Str::uuid();
        $ext = $request->file('file')->getClientOriginalExtension() ?: 'csv';
        $storagePath = "imports/{$jobId}.{$ext}";

        Storage::disk('local')->putFileAs(
            'imports',
            $request->file('file'),
            "{$jobId}.{$ext}",
        );

        ProcessServiceImport::dispatch(
            $storagePath,
            $request->user()->id,
            "import.services.{$jobId}",
            $dryRun,
        );

        return response()->json(['job_id' => $jobId, 'dry_run' => $dryRun], 202);
    }

    /**
     * Devuelve el estado/resultado de un job de importación almacenado en caché.
     * { status: 'pending' | 'completed' | 'failed', ... }
     */
    public function importResult(string $jobId): JsonResponse
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $jobId)) {
            return response()->json(['status' => 'not_found'], 404);
        }

        $result = Cache::get("import.services.{$jobId}");

        if ($result === null) {
            return response()->json(['status' => 'pending']);
        }

        if (($result['status'] ?? '') === 'failed') {
            return response()->json([
                'status' => 'failed',
                'message' => $result['message'] ?? 'Error en la importación.',
            ]);
        }

        $dryRun = $result['dry_run'] ?? false;
        $parts = [];
        if (($result['imported'] ?? 0) > 0) {
            $parts[] = $result['imported'].' creado(s)';
        }
        if (($result['updated'] ?? 0) > 0) {
            $parts[] = $result['updated'].' actualizado(s)';
        }
        if (($result['skipped'] ?? 0) > 0) {
            $parts[] = $result['skipped'].' omitido(s)';
        }
        $summary = $parts !== [] ? implode(', ', $parts) : 'Sin cambios';
        $prefix = $dryRun ? 'Simulación' : 'Importación';

        return response()->json([
            'status' => 'completed',
            'message' => "{$prefix} de servicios: {$summary}.",
            'dry_run' => $dryRun,
            'imported' => $result['imported'] ?? 0,
            'updated' => $result['updated'] ?? 0,
            'skipped' => $result['skipped'] ?? 0,
            'issues' => $result['issues'] ?? [],
        ]);
    }
}
