<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyRecurringService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Plantilla Excel/CSV de cargos fijos mensuales por empresa (misma estructura para descargar, exportar e importar).
 */
final class CompanyRecurringSpreadsheetService
{
    public const MAX_IMPORT_ROWS = 2000;

    /** @var list<string> */
    public const HEADERS = [
        'NIT empresa',
        'Descripción',
        'Importe mensual',
        'Tipo',
        'Activo',
        'Orden',
    ];

    public function __construct(
        private ServiceCatalogSpreadsheetImporter $spreadsheetReader,
    ) {}

    /**
     * @return array{imported: int, updated: int, skipped: int, issues: list<array{line: int, message: string}>}
     */
    public function import(UploadedFile $file): array
    {
        $matrix = $this->spreadsheetReader->readRawMatrix($file);
        [$startIdx, $map] = $this->resolveColumnMap($matrix);

        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $issues = [];
        $dataRows = 0;

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
            $nit = $this->cellStr($this->getCell($row, $map['nit']));
            $desc = $this->cellStr($this->getCell($row, $map['description']));
            $amountRaw = $this->getCell($row, $map['amount']);
            $kindRaw = $this->cellStr($this->getCell($row, $map['kind'] ?? -1));
            $activeRaw = $this->cellStr($this->getCell($row, $map['active'] ?? -1));
            $orderRaw = $this->getCell($row, $map['order'] ?? -1);

            if ($nit === '') {
                $issues[] = ['line' => $line, 'message' => 'Falta NIT de empresa.'];
                $skipped++;

                continue;
            }
            if ($desc === '') {
                $issues[] = ['line' => $line, 'message' => 'Falta descripción del cargo.'];
                $skipped++;

                continue;
            }

            $company = $this->findCompanyByNit($nit);
            if ($company === null) {
                $issues[] = ['line' => $line, 'message' => 'No hay empresa registrada con NIT «'.$nit.'».'];
                $skipped++;

                continue;
            }

            try {
                $amount = $this->normalizeAmount($amountRaw);
            } catch (\InvalidArgumentException $e) {
                $issues[] = ['line' => $line, 'message' => $e->getMessage()];
                $skipped++;

                continue;
            }

            try {
                $billingKind = $this->normalizeBillingKind($kindRaw);
            } catch (\InvalidArgumentException $e) {
                $issues[] = ['line' => $line, 'message' => $e->getMessage()];
                $skipped++;

                continue;
            }

            $isActive = $this->normalizeActive($activeRaw);
            $sortOrder = $this->normalizeSortOrder($orderRaw, $company);

            $existing = CompanyRecurringService::query()
                ->where('company_id', $company->id)
                ->whereRaw('LOWER(TRIM(description)) = ?', [mb_strtolower($desc)])
                ->first();

            if ($existing !== null) {
                $existing->billing_kind = $billingKind;
                $existing->amount = $amount;
                $existing->is_active = $isActive;
                $existing->sort_order = $sortOrder;
                $existing->save();
                $updated++;

                continue;
            }

            CompanyRecurringService::query()->create([
                'company_id' => $company->id,
                'catalog_id' => null,
                'billing_kind' => $billingKind,
                'service_type' => null,
                'description' => $desc,
                'amount' => $amount,
                'is_active' => $isActive,
                'sort_order' => $sortOrder,
            ]);
            $imported++;
        }

        if ($dataRows === 0) {
            throw ValidationException::withMessages([
                'file' => ['No hay filas de datos (solo encabezados o archivo vacío).'],
            ]);
        }

        return [
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'issues' => $issues,
        ];
    }

    /**
     * @param  callable(array<int, string|null>): void  $writeRow
     */
    public function streamExportRows(callable $writeRow): void
    {
        $writeRow(self::HEADERS);

        CompanyRecurringService::query()
            ->with('company:id,nombre,nit')
            ->whereHas('company', fn ($q) => $q->where('es_cliente_puntual', false))
            ->orderBy('company_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->chunk(200, function ($rows) use ($writeRow) {
                foreach ($rows as $row) {
                    $writeRow([
                        $row->company?->nit ?? '',
                        (string) ($row->description ?? ''),
                        number_format((float) $row->amount, 2, '.', ''),
                        (string) $row->billing_kind,
                        $row->is_active ? 'si' : 'no',
                        (string) (int) $row->sort_order,
                    ]);
                }
            });
    }

    /**
     * @param  list<list<mixed>>  $matrix
     * @return array{0: int, 1: array<string, int>}
     */
    private function resolveColumnMap(array $matrix): array
    {
        $headerScan = $this->findHeaderRow($matrix);
        if ($headerScan !== null) {
            return [$headerScan[0] + 1, $headerScan[1]];
        }

        return [0, [
            'nit' => 0,
            'description' => 1,
            'amount' => 2,
            'kind' => 3,
            'active' => 4,
            'order' => 5,
        ]];
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
                if (str_contains($h, 'nit')) {
                    $map['nit'] = (int) $colIdx;
                }
                if (str_contains($h, 'descrip')) {
                    $map['description'] = (int) $colIdx;
                }
                if (str_contains($h, 'importe') || str_contains($h, 'monto') || str_contains($h, 'valor')) {
                    $map['amount'] = (int) $colIdx;
                }
                if ($h === 'tipo' || str_contains($h, 'clase')) {
                    $map['kind'] = (int) $colIdx;
                }
                if (str_contains($h, 'activo')) {
                    $map['active'] = (int) $colIdx;
                }
                if (str_contains($h, 'orden')) {
                    $map['order'] = (int) $colIdx;
                }
            }
            if (isset($map['nit'], $map['description'], $map['amount'])) {
                return [(int) $idx, $map];
            }
        }

        return null;
    }

    private function findCompanyByNit(string $nit): ?Company
    {
        $needle = $this->normalizeNitKey($nit);
        if ($needle === '') {
            return null;
        }

        $companies = Company::query()
            ->where('es_cliente_puntual', false)
            ->get(['id', 'nit']);

        foreach ($companies as $c) {
            if ($this->normalizeNitKey((string) $c->nit) === $needle) {
                return $c;
            }
        }

        return null;
    }

    private function normalizeNitKey(string $nit): string
    {
        return preg_replace('/\D/', '', $nit) ?? '';
    }

    private function normalizeAmount(mixed $raw): string
    {
        if ($raw === null || $raw === '') {
            throw new \InvalidArgumentException('Falta importe mensual.');
        }
        if (is_numeric($raw)) {
            $n = (float) $raw;
            if ($n < 0.01) {
                throw new \InvalidArgumentException('Importe mensual debe ser ≥ 0,01.');
            }

            return number_format($n, 2, '.', '');
        }
        $s = str_replace([' ', '$'], '', $this->cellStr($raw));
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
        if (! is_numeric($s)) {
            throw new \InvalidArgumentException('Importe mensual no válido.');
        }
        $n = (float) $s;
        if ($n < 0.01) {
            throw new \InvalidArgumentException('Importe mensual debe ser ≥ 0,01.');
        }

        return number_format($n, 2, '.', '');
    }

    private function normalizeBillingKind(string $raw): string
    {
        $k = mb_strtolower(trim($raw));
        if ($k === '' || $k === 'servicio' || $k === 'servicios') {
            return 'servicio';
        }
        if ($k === 'venta' || $k === 'ventas') {
            return 'venta';
        }
        if ($k === 'alquiler' || $k === 'alquileres') {
            return 'alquiler';
        }

        throw new \InvalidArgumentException('Tipo debe ser servicio, venta o alquiler.');
    }

    private function normalizeActive(string $raw): bool
    {
        $k = mb_strtolower(trim($raw));
        if ($k === '' || $k === 'si' || $k === 'sí' || $k === '1' || $k === 'true' || $k === 'activo') {
            return true;
        }
        if ($k === 'no' || $k === '0' || $k === 'false' || $k === 'inactivo') {
            return false;
        }

        return true;
    }

    private function normalizeSortOrder(mixed $raw, Company $company): int
    {
        if ($raw === null || $raw === '') {
            return (int) (CompanyRecurringService::query()
                ->where('company_id', $company->id)
                ->max('sort_order') ?? -1) + 1;
        }
        if (! is_numeric($raw)) {
            return (int) (CompanyRecurringService::query()
                ->where('company_id', $company->id)
                ->max('sort_order') ?? -1) + 1;
        }

        return max(0, (int) $raw);
    }

    /**
     * @param  list<mixed>  $row
     */
    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $cell) {
            if ($this->cellStr($cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function getCell(array $row, int $col): mixed
    {
        return $row[$col] ?? null;
    }

    private function cellStr(mixed $cell): string
    {
        if ($cell === null) {
            return '';
        }

        return trim((string) $cell);
    }
}
