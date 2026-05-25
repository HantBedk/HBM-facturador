<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ServiceRegistrySpreadsheetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function import(Request $request, ServiceRegistrySpreadsheetService $service): JsonResponse
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
        $result = $service->import($request->file('file'), $request->user(), $dryRun);

        $parts = [];
        if ($result['imported'] > 0) {
            $parts[] = $result['imported'].' creado(s)';
        }
        if ($result['updated'] > 0) {
            $parts[] = $result['updated'].' actualizado(s)';
        }
        if ($result['skipped'] > 0) {
            $parts[] = $result['skipped'].' omitido(s)';
        }
        $summary = $parts !== [] ? implode(', ', $parts) : 'Sin cambios';
        $prefix = $dryRun ? 'Simulación' : 'Importación';

        return response()->json([
            'message' => $prefix.' de servicios: '.$summary.'.',
            'dry_run' => $dryRun,
            'imported' => $result['imported'],
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
            'issues' => $result['issues'],
        ]);
    }
}
