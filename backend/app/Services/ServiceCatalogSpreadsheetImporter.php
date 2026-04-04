<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Lee la primera hoja de Excel (.xlsx, .xls) o CSV y devuelve filas normalizadas para alta en catálogo.
 *
 * Fila de encabezados (opcional): detecta columnas por texto en español/inglés (nombre, precio, descripción…).
 * Sin encabezados: columnas A = nombre, B = descripción (opcional), C = precio; si solo hay dos columnas, B = precio.
 */
final class ServiceCatalogSpreadsheetImporter
{
    public const MAX_ROWS = 2000;

    /**
     * @return list<array{line: int, name: string, description: ?string, base_price: float}>
     */
    public function parse(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if ($path === false || ! is_readable($path)) {
            throw ValidationException::withMessages([
                'file' => ['No se pudo leer el archivo subido.'],
            ]);
        }

        try {
            $spreadsheet = IOFactory::load($path);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'file' => ['No se pudo abrir el archivo. Use Excel (.xlsx, .xls) o CSV codificado en UTF-8.'],
            ]);
        }

        $sheet = $spreadsheet->getActiveSheet();
        /** @var list<list<mixed>> $data */
        $data = $sheet->toArray(null, true, true, false);

        if ($data === []) {
            throw ValidationException::withMessages([
                'file' => ['El archivo no contiene datos.'],
            ]);
        }

        $data = $this->trimTrailingEmptyRows($data);
        if ($data === []) {
            throw ValidationException::withMessages([
                'file' => ['El archivo no contiene filas con datos.'],
            ]);
        }

        $headerMap = $this->classifyHeaderRow($data[0]);
        $hasHeader = isset($headerMap['name'], $headerMap['price']);

        if ($hasHeader) {
            $colName = $headerMap['name'];
            $colPrice = $headerMap['price'];
            $colDesc = $headerMap['description'] ?? null;
            $startIdx = 1;
        } else {
            [$colName, $colDesc, $colPrice] = $this->defaultColumns($data);
            $startIdx = 0;
        }

        $out = [];
        for ($i = $startIdx; $i < count($data); $i++) {
            $row = $data[$i];
            if ($this->rowIsEmpty($row)) {
                continue;
            }
            $sheetLine = $i + 1;

            $name = $this->cellStr($this->getCell($row, $colName));
            $descRaw = $colDesc !== null ? $this->getCell($row, $colDesc) : null;
            $description = $descRaw === null || $descRaw === '' ? null : mb_substr($this->cellStr($descRaw), 0, 5000);
            $priceRaw = $this->getCell($row, $colPrice);
            $basePrice = $this->normalizePrice($priceRaw);

            if ($name === '') {
                continue;
            }
            if ($basePrice === null || $basePrice < 0.01) {
                throw ValidationException::withMessages([
                    'file' => ["Fila {$sheetLine}: precio inválido o menor a 0,01 (valor recibido: «{$this->cellStr($priceRaw)}»)."],
                ]);
            }

            $out[] = [
                'line' => $sheetLine,
                'name' => $name,
                'description' => $description !== '' ? $description : null,
                'base_price' => round($basePrice, 2),
            ];

            if (count($out) > self::MAX_ROWS) {
                throw ValidationException::withMessages([
                    'file' => ['Máximo '.self::MAX_ROWS.' filas de datos por archivo.'],
                ]);
            }
        }

        if ($out === []) {
            throw ValidationException::withMessages([
                'file' => ['No se encontraron filas con nombre y precio válidos.'],
            ]);
        }

        return $out;
    }

    /**
     * @param  list<list<mixed>>  $data
     * @return array{0: int, 1: int|null, 2: int}
     */
    private function defaultColumns(array $data): array
    {
        $first = $data[0];
        $nonEmpty = 0;
        foreach ($first as $v) {
            if ($v !== null && trim((string) $v) !== '') {
                $nonEmpty++;
            }
        }
        if ($nonEmpty >= 3) {
            return [0, 1, 2];
        }

        return [0, null, 1];
    }

    /**
     * @param  list<mixed>  $row
     */
    private function getCell(array $row, int $idx): mixed
    {
        return $row[$idx] ?? null;
    }

    /**
     * @param  list<mixed>  $firstRow
     * @return array<string, int>
     */
    private function classifyHeaderRow(array $firstRow): array
    {
        $map = [];
        foreach ($firstRow as $idx => $cell) {
            $role = $this->classifyHeaderCell($cell);
            if ($role !== null && ! isset($map[$role])) {
                $map[$role] = (int) $idx;
            }
        }

        return $map;
    }

    private function classifyHeaderCell(mixed $cell): ?string
    {
        $h = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $cell)));
        if ($h === '') {
            return null;
        }

        $priceNeedles = ['precio lista', 'precio de lista', 'precio', 'price', 'valor', 'base price', 'base_price', 'tarifa'];
        foreach ($priceNeedles as $n) {
            if (str_contains($h, $n)) {
                return 'price';
            }
        }

        $descNeedles = ['descripción', 'descripcion', 'description', 'detalle', 'notas', 'observ'];
        foreach ($descNeedles as $n) {
            if (str_contains($h, $n)) {
                return 'description';
            }
        }

        $nameNeedles = ['nombre', 'name', 'servicio', 'concepto', 'ítem', 'item', 'rubro'];
        foreach ($nameNeedles as $n) {
            if (str_contains($h, $n)) {
                return 'name';
            }
        }

        return null;
    }

    /**
     * @param  list<list<mixed>>  $rows
     * @return list<list<mixed>>
     */
    private function trimTrailingEmptyRows(array $rows): array
    {
        while ($rows !== []) {
            $last = $rows[array_key_last($rows)];
            if ($this->rowIsEmpty($last)) {
                array_pop($rows);
            } else {
                break;
            }
        }

        return $rows;
    }

    /**
     * @param  list<mixed>  $row
     */
    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $v) {
            if ($v !== null && trim((string) $v) !== '') {
                return false;
            }
        }

        return true;
    }

    private function cellStr(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_float($v) && is_finite($v)) {
            $s = (string) $v;
            if (str_contains($s, 'E') || str_contains($s, 'e')) {
                $s = rtrim(rtrim(sprintf('%.10F', $v), '0'), '.');
            }

            return trim($s);
        }

        return trim((string) $v);
    }

    private function normalizePrice(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            $f = (float) $v;

            return $f >= 0.01 ? $f : null;
        }

        $s = trim((string) $v);
        $s = str_replace(["\xc2\xa0", ' '], '', $s);
        $s = preg_replace('/[^\d,.\-]/u', '', $s) ?? '';
        if ($s === '' || $s === '-') {
            return null;
        }

        $lastComma = strrpos($s, ',');
        $lastDot = strrpos($s, '.');
        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {
            $s = str_replace(',', '', $s);
        }

        if (! is_numeric($s)) {
            return null;
        }
        $f = (float) $s;

        return $f >= 0.01 ? $f : null;
    }
}
