<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CompanyRecurringSpreadsheetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminRecurringFixedChargesSpreadsheetController extends Controller
{
    /**
     * Plantilla vacía (mismas columnas que exportación e importación).
     */
    public function template(): StreamedResponse
    {
        $filename = 'plantilla-cargos-fijos-mensuales.csv';

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, CompanyRecurringSpreadsheetService::HEADERS, ';');
            fputcsv($out, [
                '900123456-1',
                'Internet empresarial',
                '150000.00',
                'servicio',
                'si',
                '0',
            ], ';');
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Todos los cargos fijos de empresas registradas (no puntuales).
     */
    public function export(CompanyRecurringSpreadsheetService $service): StreamedResponse
    {
        $filename = 'cargos-fijos-mensuales-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($service) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $service->streamExportRows(function (array $row) use ($out) {
                fputcsv($out, $row, ';');
            });
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function import(Request $request, CompanyRecurringSpreadsheetService $service): JsonResponse
    {
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
                        $fail('El archivo está vacío o no se recibió el contenido.');
                    }
                },
            ],
        ]);

        $result = $service->import($request->file('file'));

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

        return response()->json([
            'message' => 'Importación de cargos fijos: '.$summary.'.',
            'imported' => $result['imported'],
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
            'issues' => $result['issues'],
        ]);
    }
}
