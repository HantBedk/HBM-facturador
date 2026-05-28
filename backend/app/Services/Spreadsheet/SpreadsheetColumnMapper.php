<?php

namespace App\Services\Spreadsheet;

use Illuminate\Validation\ValidationException;

final class SpreadsheetColumnMapper
{
    use SpreadsheetCellTrait;

    /**
     * Detect header row in the matrix and return [startDataRowIndex, columnMap].
     *
     * @param  list<list<mixed>>  $matrix
     * @return array{0: int, 1: array<string, int>}
     *
     * @throws ValidationException
     */
    public function resolve(array $matrix): array
    {
        $found = $this->findHeaderRow($matrix);
        if ($found !== null) {
            return $found;
        }

        throw ValidationException::withMessages([
            'file' => [
                'No se reconocieron las columnas. Use el CSV exportado desde esta pantalla o la plantilla (encabezados en español, separador ;).',
            ],
        ]);
    }

    /**
     * @param  list<list<mixed>>  $matrix
     * @return array{0: int, 1: array<string, int>}|null
     */
    private function findHeaderRow(array $matrix): ?array
    {
        foreach ($matrix as $idx => $row) {
            if ($this->rowIsEmpty($row)) {
                continue;
            }
            $map = [];
            foreach ($row as $colIdx => $cell) {
                $h = mb_strtolower($this->cellStr($cell));
                if ($h === '') {
                    continue;
                }
                if (str_contains($h, 'fecha') && str_contains($h, 'serv')) {
                    $map['service_date'] = (int) $colIdx;
                } elseif ($h === 'empresa') {
                    $map['company_name'] = (int) $colIdx;
                } elseif (str_contains($h, 'nit')) {
                    $map['nit'] = (int) $colIdx;
                } elseif (str_contains($h, 'código servicio') || str_contains($h, 'codigo servicio')) {
                    $map['code'] = (int) $colIdx;
                } elseif (str_contains($h, 'clase')) {
                    $map['kind'] = (int) $colIdx;
                } elseif (str_contains($h, 'id activo')) {
                    $map['inventory_lot_id'] = (int) $colIdx;
                } elseif (str_contains($h, 'tipo trabajo')) {
                    $map['service_type'] = (int) $colIdx;
                } elseif (str_contains($h, 'descrip') && ! str_contains($h, 'equipo')) {
                    $map['description'] = (int) $colIdx;
                } elseif ($h === 'empleado' || str_contains($h, 'técnico') || str_contains($h, 'tecnico')) {
                    $map['employee'] = (int) $colIdx;
                } elseif (str_contains($h, 'cliente')) {
                    $map['client_name'] = (int) $colIdx;
                } elseif ($h === 'valor') {
                    $map['amount'] = (int) $colIdx;
                } elseif ($h === 'estado') {
                    $map['status'] = (int) $colIdx;
                }
            }

            $hasCore = isset($map['service_date'], $map['description'], $map['amount'])
                && (isset($map['nit']) || isset($map['company_name']))
                && isset($map['client_name'], $map['service_type']);

            if ($hasCore) {
                return [(int) $idx + 1, $map];
            }
        }

        return null;
    }
}
