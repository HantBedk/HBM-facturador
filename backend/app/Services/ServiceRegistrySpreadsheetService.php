<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Service;
use App\Models\User;
use App\Services\Spreadsheet\SpreadsheetColumnMapper;
use App\Services\Spreadsheet\SpreadsheetRowImporter;
use App\Services\Spreadsheet\SpreadsheetRowParser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Exportación e importación CSV/Excel de servicios y mantenimientos (mismas columnas).
 * Orquesta SpreadsheetColumnMapper → SpreadsheetRowParser → SpreadsheetRowImporter.
 */
final class ServiceRegistrySpreadsheetService
{
    public const MAX_IMPORT_ROWS = 5000;

    /** Columnas del CSV exportado (UTF-8 BOM, separador `;`). */
    public const EXPORT_HEADERS = [
        'Fecha servicio',
        'Empresa',
        'NIT empresa',
        'Código servicio',
        'Clase registro',
        'ID activo inventario',
        'Equipo (nombre)',
        'Equipo código interno',
        'Tipo trabajo',
        'Descripción',
        'Empleado',
        'Cliente u obra',
        'Valor',
        'Estado',
    ];

    public function __construct(
        private ServiceCatalogSpreadsheetImporter $spreadsheetReader,
        private SpreadsheetColumnMapper $columnMapper,
        private SpreadsheetRowParser $rowParser,
        private SpreadsheetRowImporter $rowImporter,
    ) {}

    /**
     * @return array{imported: int, updated: int, skipped: int, issues: list<array{line: int, message: string}>}
     */
    public function import(UploadedFile $file, User $actor, bool $dryRun = false): array
    {
        $matrix = $this->spreadsheetReader->readRawMatrix($file);
        [$startIdx, $map] = $this->columnMapper->resolve($matrix);

        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $issues = [];
        $dataRows = 0;

        $processRow = function () use (
            &$imported, &$updated, &$skipped, &$issues, &$dataRows,
            $matrix, $startIdx, $map, $actor, $dryRun
        ) {
            for ($i = $startIdx; $i < count($matrix); $i++) {
                $row = $matrix[$i];
                if ($this->rowIsEmpty($row)) {
                    continue;
                }
                $dataRows++;
                if ($dataRows > self::MAX_IMPORT_ROWS) {
                    throw ValidationException::withMessages([
                        'file' => ['Demasiadas filas (máx. '.self::MAX_IMPORT_ROWS.').'],
                    ]);
                }

                $line = $i + 1;
                try {
                    $payload = $this->rowParser->parse($row, $map, $actor);
                    $existing = null;
                    if ($payload['code'] !== '') {
                        $existing = Service::query()->where('code', $payload['code'])->first();
                    }

                    if ($dryRun) {
                        $existing !== null ? $updated++ : $imported++;
                        continue;
                    }

                    if ($existing !== null) {
                        $this->rowImporter->update($existing, $payload);
                        $updated++;
                    } else {
                        $this->rowImporter->create($payload, $actor);
                        $imported++;
                    }
                } catch (\InvalidArgumentException $e) {
                    $issues[] = ['line' => $line, 'message' => $e->getMessage()];
                    $skipped++;
                }
            }
        };

        $dryRun ? $processRow() : DB::transaction($processRow);

        return [
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'issues' => $issues,
        ];
    }

    /** @param list<mixed> $row */
    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) ($cell ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }
}
