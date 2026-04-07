<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use League\Csv\Exception as LeagueCsvException;
use League\Csv\Reader;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as SpreadsheetReaderException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Lee la primera hoja de Excel (.xlsx, .xls) o CSV y devuelve filas normalizadas para alta en catálogo.
 *
 * Fila de encabezados (opcional): detecta columnas por texto en español/inglés (nombre, precio, descripción…).
 * Sin encabezados: columnas A = nombre, B = descripción (opcional), C = precio; si solo hay dos columnas, B = precio.
 *
 * CSV: **League CSV** (`league/csv`): separador `,`, `;`, tab o `|`; UTF-8 (BOM opcional), UTF-16 LE/BE con BOM, o Windows-1252 / ISO-8859-1 / CP850.
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
            if ($this->shouldReadAsCsv($path, $file)) {
                /** @var list<list<mixed>> $data */
                $data = $this->readCsvAsRowMatrix($path);
            } else {
                $spreadsheet = $this->loadOfficeSpreadsheet($path, $file);
                $sheet = $spreadsheet->getActiveSheet();
                /** @var list<list<mixed>> $data */
                $data = $sheet->toArray(null, true, true, false);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (LeagueCsvException $e) {
            report($e);
            throw ValidationException::withMessages([
                'file' => ['No se pudo analizar el CSV. Compruebe separadores y comillas. Detalle: '.$e->getMessage()],
            ]);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages([
                'file' => [$e->getMessage()],
            ]);
        } catch (SpreadsheetReaderException $e) {
            throw ValidationException::withMessages([
                'file' => [
                    'No se pudo abrir el libro (¿archivo dañado o no es Excel/CSV?). Detalle: '.$e->getMessage(),
                ],
            ]);
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages([
                'file' => [
                    'No se pudo leer el archivo. Use .xlsx / .xls, o CSV con columnas separadas por coma o punto y coma y texto en UTF-8 (o Excel regional / ANSI).',
                ],
            ]);
        }

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

        $headerScan = $this->findRecognizedHeaderRow($data);
        if ($headerScan !== null) {
            [$headerRowIdx, $headerMap] = $headerScan;
            $colName = $headerMap['name'];
            $colPrice = $headerMap['price'];
            $colDesc = $headerMap['description'] ?? null;
            $startIdx = $headerRowIdx + 1;
        } else {
            $firstIdx = $this->firstNonEmptyRowIndex($data);
            if ($firstIdx === null) {
                throw ValidationException::withMessages([
                    'file' => ['El archivo no contiene filas con datos.'],
                ]);
            }
            [$colName, $colDesc, $colPrice] = $this->defaultColumnsForRow($data[$firstIdx]);
            $startIdx = $firstIdx;
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
     * Busca encabezados tipo «Nombre / Precio» en las primeras filas (títulos o filas vacías arriba en Excel).
     *
     * @param  list<list<mixed>>  $data
     * @return array{0: int, 1: array<string, int>}|null
     */
    private function findRecognizedHeaderRow(array $data): ?array
    {
        $max = min(30, count($data));
        for ($r = 0; $r < $max; $r++) {
            $map = $this->classifyHeaderRow($data[$r]);
            if (isset($map['name'], $map['price'])) {
                return [$r, $map];
            }
        }

        return null;
    }

    /**
     * @param  list<list<mixed>>  $data
     */
    private function firstNonEmptyRowIndex(array $data): ?int
    {
        foreach ($data as $idx => $row) {
            if (! $this->rowIsEmpty($row)) {
                return (int) $idx;
            }
        }

        return null;
    }

    /**
     * @param  list<mixed>  $firstRow
     * @return array{0: int, 1: int|null, 2: int}
     */
    private function defaultColumnsForRow(array $firstRow): array
    {
        $nonEmpty = 0;
        foreach ($firstRow as $v) {
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
        $s = $this->scrubUtf8String((string) $cell);
        $collapsed = preg_replace('/\s+/u', ' ', trim($s));
        if ($collapsed === null) {
            $collapsed = preg_replace('/\s+/', ' ', trim($s)) ?? '';
        }
        $h = mb_strtolower($collapsed, 'UTF-8');
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

        return $this->scrubUtf8String(trim((string) $v));
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

        $s = $this->scrubUtf8String(trim((string) $v));
        $s = str_replace(["\xc2\xa0", ' '], '', $s);
        $s = preg_replace('/[^\d,.\-]/', '', $s) ?? '';
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

    private function shouldReadAsCsv(string $path, UploadedFile $file): bool
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if ($ext === '') {
            $ext = strtolower((string) $file->guessExtension());
        }

        if (in_array($ext, ['csv', 'txt'], true)) {
            return true;
        }

        if (in_array($ext, ['xlsx', 'xls', 'xlsm', 'xltx', 'xltm'], true)) {
            return false;
        }

        return $this->sniffUploadedSpreadsheetKind($path) === 'csv';
    }

    private function loadOfficeSpreadsheet(string $path, UploadedFile $file): Spreadsheet
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if ($ext === '') {
            $ext = strtolower((string) $file->guessExtension());
        }

        if (in_array($ext, ['xlsx', 'xls', 'xlsm', 'xltx', 'xltm'], true)) {
            return $this->loadUploadedOfficeFileViaTempCopy($path, $ext);
        }

        return match ($this->sniffUploadedSpreadsheetKind($path)) {
            'xls' => $this->loadUploadedOfficeFileViaTempCopy($path, 'xls'),
            default => $this->loadUploadedOfficeFileViaTempCopy($path, 'xlsx'),
        };
    }

    /**
     * Copia a un temporal con extensión real para que IOFactory::createReaderForFile() resuelva Xlsx/Xls de forma fiable.
     */
    private function loadUploadedOfficeFileViaTempCopy(string $path, string $ext): Spreadsheet
    {
        $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hbm_office_'.bin2hex(random_bytes(10)).'.'.$ext;
        try {
            if (! @copy($path, $tmp)) {
                throw new \RuntimeException('No se pudo preparar el archivo Excel para lectura.');
            }

            return IOFactory::load($tmp);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * Matriz de filas con League CSV (SplFileObject interno, escape vacío compatible PHP 8.4).
     * Incluye filas vacías para que los números de fila coincidan con el archivo.
     *
     * @return list<list<mixed>>
     */
    private function readCsvAsRowMatrix(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            throw new \RuntimeException('El CSV no tiene contenido (0 bytes). Compruebe el archivo o suba desde el formulario de la aplicación, no desde un cURL copiado del navegador sin el cuerpo del archivo.');
        }

        $utf8 = $this->normalizeCsvBytesToUtf8($raw);
        $delimiter = $this->detectCsvDelimiter($utf8);

        $reader = Reader::fromString($utf8);
        $reader->setDelimiter($delimiter);
        $reader->setEnclosure('"');
        $reader->setEscape('');
        $reader->skipInputBOM();
        $reader->includeEmptyRecords();

        $data = [];
        foreach ($reader->getRecords() as $record) {
            $data[] = array_values((array) $record);
        }

        return $data;
    }

    /**
     * @return 'csv'|'xls'|'xlsx'
     */
    private function sniffUploadedSpreadsheetKind(string $path): string
    {
        $head = @file_get_contents($path, false, null, 0, 8) ?: '';
        if (str_starts_with($head, "PK\x03\x04")) {
            return 'xlsx';
        }
        if (str_starts_with($head, "\xD0\xCF\x11\x0A")) {
            return 'xls';
        }
        if (str_starts_with($head, "\xFF\xFE") || str_starts_with($head, "\xFE\xFF")) {
            return 'csv';
        }
        if (str_starts_with($head, "\xEF\xBB\xBF")) {
            return 'csv';
        }
        if ($this->fileLooksLikeDelimitedText($path)) {
            return 'csv';
        }

        return 'xlsx';
    }

    private function fileLooksLikeDelimitedText(string $path): bool
    {
        $raw = @file_get_contents($path, false, null, 0, 65536);
        if ($raw === false || $raw === '') {
            return false;
        }
        if (str_starts_with($raw, "PK\x03\x04") || str_starts_with($raw, "\xD0\xCF\x11\x0A")) {
            return false;
        }

        $utf8 = $this->normalizeCsvBytesToUtf8($raw);
        $lines = preg_split("/\r\n|\n|\r/", $utf8) ?: [];
        foreach ($lines as $candidate) {
            $t = trim((string) $candidate);
            if ($t === '') {
                continue;
            }
            $n = max(
                substr_count($t, ';'),
                substr_count($t, ','),
                substr_count($t, "\t"),
                substr_count($t, '|'),
            );

            return $n >= 1;
        }

        return false;
    }

    private function normalizeCsvBytesToUtf8(string $raw): string
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }

        if (str_starts_with($raw, "\xFF\xFE")) {
            $body = substr($raw, 2);
            $converted = @mb_convert_encoding($body, 'UTF-8', 'UTF-16LE');

            return $this->scrubUtf8String(($converted !== false && $converted !== '') ? $converted : $raw);
        }
        if (str_starts_with($raw, "\xFE\xFF")) {
            $body = substr($raw, 2);
            $converted = @mb_convert_encoding($body, 'UTF-8', 'UTF-16BE');

            return $this->scrubUtf8String(($converted !== false && $converted !== '') ? $converted : $raw);
        }

        if (mb_check_encoding($raw, 'UTF-8')) {
            return $this->scrubUtf8String($raw);
        }

        foreach (['Windows-1252', 'ISO-8859-1', 'CP850'] as $from) {
            $converted = @mb_convert_encoding($raw, 'UTF-8', $from);
            if ($converted !== false && $converted !== '' && mb_check_encoding($converted, 'UTF-8')) {
                return $this->scrubUtf8String($converted);
            }
        }

        $forced = @mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        if ($forced !== false && $forced !== '' && mb_check_encoding($forced, 'UTF-8')) {
            return $this->scrubUtf8String($forced);
        }

        $relaxed = function_exists('iconv') ? @iconv('UTF-8', 'UTF-8//IGNORE', $raw) : false;

        return $this->scrubUtf8String(($relaxed !== false && $relaxed !== '') ? $relaxed : ($forced !== false ? $forced : ''));
    }

    /**
     * Elimina secuencias UTF-8 inválidas (evita fallos en preg_ y funciones mb_ con modificador u y texto mal codificado).
     */
    private function scrubUtf8String(string $s): string
    {
        if ($s === '') {
            return '';
        }
        if (! function_exists('iconv')) {
            return $s;
        }
        $out = @iconv('UTF-8', 'UTF-8//IGNORE', $s);

        return ($out !== false) ? $out : $s;
    }

    private function detectCsvDelimiter(string $utf8Content): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $utf8Content) ?: [];
        $line = '';
        foreach ($lines as $candidate) {
            if (trim((string) $candidate) !== '') {
                $line = (string) $candidate;
                break;
            }
        }

        if ($line === '') {
            return ',';
        }

        $scores = [
            ';' => substr_count($line, ';'),
            ',' => substr_count($line, ','),
            "\t" => substr_count($line, "\t"),
            '|' => substr_count($line, '|'),
        ];

        arsort($scores);
        $best = array_key_first($scores);
        $max = $scores[$best] ?? 0;

        return $max > 0 ? $best : ',';
    }
}
