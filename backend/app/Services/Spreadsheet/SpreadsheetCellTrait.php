<?php

namespace App\Services\Spreadsheet;

trait SpreadsheetCellTrait
{
    private function cellStr(mixed $cell): string
    {
        if ($cell === null) {
            return '';
        }

        return trim((string) $cell);
    }

    /** @param list<mixed> $row */
    private function getCell(array $row, int $idx): mixed
    {
        if ($idx < 0) {
            return null;
        }

        return $row[$idx] ?? null;
    }

    /** @param list<mixed> $row */
    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $cell) {
            if ($this->cellStr($cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
