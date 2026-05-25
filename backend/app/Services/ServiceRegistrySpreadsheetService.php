<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\InventoryLot;
use App\Models\Service;
use App\Models\ServiceItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Exportación e importación CSV/Excel de servicios y mantenimientos (mismas columnas).
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
        private ServiceCodeGenerator $codes,
    ) {}

    /**
     * @return array{imported: int, updated: int, skipped: int, issues: list<array{line: int, message: string}>}
     */
    public function import(UploadedFile $file, User $actor, bool $dryRun = false): array
    {
        $matrix = $this->spreadsheetReader->readRawMatrix($file);
        [$startIdx, $map] = $this->resolveColumnMap($matrix);

        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $issues = [];
        $dataRows = 0;

        $processRow = function () use (
            &$imported,
            &$updated,
            &$skipped,
            &$issues,
            &$dataRows,
            $matrix,
            $startIdx,
            $map,
            $actor,
            $dryRun
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
                    $payload = $this->parseRow($row, $map, $actor);
                    $existing = null;
                    if ($payload['code'] !== '') {
                        $existing = Service::query()->where('code', $payload['code'])->first();
                    }

                    if ($dryRun) {
                        if ($existing !== null) {
                            $updated++;
                        } else {
                            $imported++;
                        }

                        continue;
                    }

                    if ($existing !== null) {
                        $this->applyUpdate($existing, $payload);
                        $updated++;
                    } else {
                        $this->applyCreate($payload, $actor);
                        $imported++;
                    }
                } catch (\InvalidArgumentException $e) {
                    $issues[] = ['line' => $line, 'message' => $e->getMessage()];
                    $skipped++;
                }
            }
        };

        if ($dryRun) {
            $processRow();
        } else {
            DB::transaction($processRow);
        }

        return [
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'issues' => $issues,
        ];
    }

    /**
     * @param  list<list<mixed>>  $matrix
     * @return array{0: int, 1: array<string, int>}
     */
    private function resolveColumnMap(array $matrix): array
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

    /**
     * @param  list<mixed>  $row
     * @param  array<string, int>  $map
     * @return array{
     *   code: string,
     *   company_id: int,
     *   user_id: int,
     *   kind: string,
     *   inventory_lot_id: ?int,
     *   service_type: string,
     *   description: string,
     *   client_name: string,
     *   amount: string,
     *   service_date: string,
     *   status: string,
     * }
     */
    private function parseRow(array $row, array $map, User $actor): array
    {
        $nit = $this->cellStr($this->getCell($row, $map['nit'] ?? -1));
        $companyName = $this->cellStr($this->getCell($row, $map['company_name'] ?? -1));
        $company = $this->resolveCompany($nit, $companyName);

        $serviceDateRaw = $this->cellStr($this->getCell($row, $map['service_date']));
        $serviceDate = $this->parseServiceDate($serviceDateRaw);

        $clientName = $this->cellStr($this->getCell($row, $map['client_name']));
        if ($clientName === '') {
            throw new \InvalidArgumentException('Falta cliente u obra.');
        }

        $serviceType = $this->cellStr($this->getCell($row, $map['service_type']));
        if ($serviceType === '') {
            throw new \InvalidArgumentException('Falta tipo de trabajo.');
        }

        $description = $this->cellStr($this->getCell($row, $map['description']));
        if (mb_strlen($description) < 8) {
            throw new \InvalidArgumentException('La descripción debe tener al menos 8 caracteres.');
        }

        $amount = $this->normalizeAmount($this->getCell($row, $map['amount']));

        $kind = $this->normalizeKind($this->cellStr($this->getCell($row, $map['kind'] ?? -1)));

        $lotIdRaw = $this->cellStr($this->getCell($row, $map['inventory_lot_id'] ?? -1));
        $inventoryLotId = null;
        if ($lotIdRaw !== '') {
            if (! ctype_digit($lotIdRaw)) {
                throw new \InvalidArgumentException('ID activo inventario no válido.');
            }
            $inventoryLotId = (int) $lotIdRaw;
        }

        if ($kind === Service::KIND_MANTENIMIENTO && $inventoryLotId === null) {
            throw new \InvalidArgumentException('Mantenimiento requiere ID activo inventario.');
        }

        if ($inventoryLotId !== null) {
            $lot = InventoryLot::query()->find($inventoryLotId);
            if ($lot === null) {
                throw new \InvalidArgumentException('No existe el activo de inventario indicado.');
            }
            if ((int) ($lot->tenant_company_id ?? 0) !== (int) $company->id) {
                throw new \InvalidArgumentException('El activo no pertenece a la empresa de la fila.');
            }
        }

        $employeeName = $this->cellStr($this->getCell($row, $map['employee'] ?? -1));
        $userId = $this->resolveUserId($employeeName, $actor);

        $status = $this->normalizeStatus($this->cellStr($this->getCell($row, $map['status'] ?? -1)));

        return [
            'code' => $this->cellStr($this->getCell($row, $map['code'] ?? -1)),
            'company_id' => (int) $company->id,
            'user_id' => $userId,
            'kind' => $kind,
            'inventory_lot_id' => $inventoryLotId,
            'service_type' => $serviceType,
            'description' => $description,
            'client_name' => $clientName,
            'amount' => $amount,
            'service_date' => $serviceDate,
            'status' => $status,
        ];
    }

    /**
     * @param  array{
     *   company_id: int,
     *   user_id: int,
     *   kind: string,
     *   inventory_lot_id: ?int,
     *   service_type: string,
     *   description: string,
     *   client_name: string,
     *   amount: string,
     *   service_date: string,
     *   status: string,
     * }  $payload
     */
    private function applyCreate(array $payload, User $actor): void
    {
        $date = Carbon::parse($payload['service_date'], config('app.timezone'))->startOfDay();
        $code = $this->codes->nextForDate($date, ServiceCodeGenerator::KIND_SERVICIO);

        $service = Service::query()->create([
            'code' => $code,
            'company_id' => $payload['company_id'],
            'inventory_lot_id' => $payload['inventory_lot_id'],
            'user_id' => $payload['user_id'],
            'kind' => $payload['kind'],
            'client_name' => $payload['client_name'],
            'service_type' => $payload['service_type'],
            'description' => $payload['description'],
            'amount' => $payload['amount'],
            'service_date' => $payload['service_date'],
            'status' => $payload['status'],
        ]);

        $this->syncSingleImportLine($service, $payload);
    }

    /**
     * @param  array{
     *   company_id: int,
     *   user_id: int,
     *   kind: string,
     *   inventory_lot_id: ?int,
     *   service_type: string,
     *   description: string,
     *   client_name: string,
     *   amount: string,
     *   service_date: string,
     *   status: string,
     * }  $payload
     */
    private function applyUpdate(Service $service, array $payload): void
    {
        if ($service->status === Service::STATUS_ELIMINADO && $payload['status'] !== Service::STATUS_ELIMINADO) {
            throw new \InvalidArgumentException('No se puede reactivar un servicio eliminado desde importación.');
        }

        $service->fill([
            'company_id' => $payload['company_id'],
            'inventory_lot_id' => $payload['inventory_lot_id'],
            'user_id' => $payload['user_id'],
            'kind' => $payload['kind'],
            'client_name' => $payload['client_name'],
            'service_type' => $payload['service_type'],
            'description' => $payload['description'],
            'amount' => $payload['amount'],
            'service_date' => $payload['service_date'],
            'status' => $payload['status'],
        ]);
        $service->save();

        $this->syncSingleImportLine($service, $payload);
    }

    /**
     * @param  array{service_type: string, description: string, amount: string}  $payload
     */
    private function syncSingleImportLine(Service $service, array $payload): void
    {
        ServiceItem::query()->where('service_id', $service->id)->delete();
        ServiceItem::query()->create([
            'service_id' => $service->id,
            'catalog_id' => null,
            'catalog_suggestion_id' => null,
            'label' => $payload['service_type'],
            'line_description' => $payload['description'],
            'amount' => $payload['amount'],
            'technician_line_amount' => $payload['amount'],
            'sort_order' => 0,
        ]);
    }

    private function resolveCompany(string $nit, string $companyName): Company
    {
        if ($nit !== '') {
            $byNit = $this->findCompanyByNit($nit);
            if ($byNit !== null) {
                return $byNit;
            }
        }

        if ($companyName !== '') {
            $needle = mb_strtolower($companyName);
            $candidates = Company::query()
                ->where('es_cliente_puntual', false)
                ->get(['id', 'nombre', 'nit', 'estado']);
            foreach ($candidates as $c) {
                if (mb_strtolower(trim((string) $c->nombre)) === $needle) {
                    if ($c->estado !== Company::ESTADO_ACTIVO) {
                        throw new \InvalidArgumentException('La empresa «'.$c->nombre.'» no está activa.');
                    }

                    return $c;
                }
            }
        }

        throw new \InvalidArgumentException('No se encontró empresa (revise NIT o nombre).');
    }

    private function findCompanyByNit(string $nit): ?Company
    {
        $needle = $this->normalizeNitKey($nit);
        if ($needle === '') {
            return null;
        }

        foreach (Company::query()->where('es_cliente_puntual', false)->get(['id', 'nombre', 'nit', 'estado']) as $c) {
            if ($this->normalizeNitKey((string) $c->nit) === $needle) {
                if ($c->estado !== Company::ESTADO_ACTIVO) {
                    throw new \InvalidArgumentException('La empresa con ese NIT no está activa.');
                }

                return $c;
            }
        }

        return null;
    }

    private function resolveUserId(string $employeeName, User $actor): int
    {
        if ($employeeName === '') {
            return (int) $actor->id;
        }

        $needle = mb_strtolower($employeeName);
        $users = User::query()
            ->whereIn('rol', [User::ROL_EMPLEADO, User::ROL_ADMIN, User::ROL_SUPER_ADMIN])
            ->get(['id', 'nombre', 'rol']);

        $exact = [];
        foreach ($users as $u) {
            if (mb_strtolower(trim((string) $u->nombre)) === $needle) {
                $exact[] = $u;
            }
        }

        if (count($exact) === 1) {
            return (int) $exact[0]->id;
        }
        if (count($exact) > 1) {
            throw new \InvalidArgumentException('Hay varios usuarios con el mismo nombre; use otro criterio.');
        }

        throw new \InvalidArgumentException('No se encontró empleado «'.$employeeName.'».');
    }

    private function parseServiceDate(string $raw): string
    {
        if ($raw === '') {
            throw new \InvalidArgumentException('Falta fecha de servicio.');
        }
        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
                return Carbon::parse($raw, config('app.timezone'))->toDateString();
            }
            if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $raw)) {
                return Carbon::createFromFormat('d/m/Y', $raw, config('app.timezone'))->toDateString();
            }
            if (preg_match('/^\d{1,2}-\d{1,2}-\d{4}$/', $raw)) {
                return Carbon::createFromFormat('d-m-Y', $raw, config('app.timezone'))->toDateString();
            }
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Fecha de servicio no válida (use AAAA-MM-DD).');
        }

        throw new \InvalidArgumentException('Fecha de servicio no válida (use AAAA-MM-DD).');
    }

    private function normalizeKind(string $raw): string
    {
        $k = mb_strtolower(trim($raw));
        if ($k === '' || $k === 'servicio' || $k === 'servicios') {
            return Service::KIND_SERVICIO;
        }
        if ($k === 'mantenimiento' || $k === 'mantenimientos' || $k === 'mantto') {
            return Service::KIND_MANTENIMIENTO;
        }

        throw new \InvalidArgumentException('Clase registro debe ser servicio o mantenimiento.');
    }

    private function normalizeStatus(string $raw): string
    {
        $k = mb_strtolower(trim($raw));
        if ($k === '' || $k === 'activo') {
            return Service::STATUS_ACTIVO;
        }
        if ($k === 'corregido') {
            return Service::STATUS_CORREGIDO;
        }
        if ($k === 'eliminado') {
            return Service::STATUS_ELIMINADO;
        }

        throw new \InvalidArgumentException('Estado debe ser activo, corregido o eliminado.');
    }

    private function normalizeAmount(mixed $raw): string
    {
        if ($raw === null || $raw === '') {
            throw new \InvalidArgumentException('Falta valor.');
        }
        if (is_numeric($raw)) {
            $n = (float) $raw;
            if ($n < 0.01) {
                throw new \InvalidArgumentException('Valor debe ser ≥ 0,01.');
            }

            return number_format($n, 2, '.', '');
        }
        $s = str_replace([' ', '$'], '', $this->cellStr($raw));
        if (str_contains($s, ',') && str_contains($s, '.')) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } elseif (str_contains($s, ',')) {
            $s = str_replace(',', '.', $s);
        }
        if (! is_numeric($s)) {
            throw new \InvalidArgumentException('Valor no válido.');
        }
        $n = (float) $s;
        if ($n < 0.01) {
            throw new \InvalidArgumentException('Valor debe ser ≥ 0,01.');
        }

        return number_format($n, 2, '.', '');
    }

    private function normalizeNitKey(string $nit): string
    {
        return preg_replace('/\D/', '', $nit) ?? '';
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

    private function cellStr(mixed $cell): string
    {
        if ($cell === null) {
            return '';
        }

        return trim((string) $cell);
    }

    /**
     * @param  list<mixed>  $row
     */
    private function getCell(array $row, int $idx): mixed
    {
        if ($idx < 0) {
            return null;
        }

        return $row[$idx] ?? null;
    }
}
