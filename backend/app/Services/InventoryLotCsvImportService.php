<?php

namespace App\Services;

use App\Models\InventoryAuditEvent;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Support\InventoryLotInternalCode;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Importación CSV de activos en custodia (inventario por empresa cliente).
 * Columnas esperadas (primera fila cabecera, separador `;`): name, quantity, asset_type, asset_subtype,
 * serial_number, brand, model, site_label, mac_address, warranty_until, physical_condition, area_label,
 * responsible_name, responsible_role, purchase_date, custody_received_at, description
 */
final class InventoryLotCsvImportService
{
    /**
     * @return array{errors: list<array{line: int, message: string}>, created_ids: list<int>, preview: list<array<string, mixed>>}
     */
    public function run(UploadedFile $file, int $tenantCompanyId, User $actor, Request $request, bool $dryRun): array
    {
        $raw = file_get_contents($file->getRealPath() ?: '');
        if ($raw === false || trim($raw) === '') {
            throw ValidationException::withMessages(['file' => ['El archivo está vacío.']]);
        }
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        $lines = preg_split("/\r\n|\n|\r/", trim($raw));
        if ($lines === false || count($lines) < 2) {
            throw ValidationException::withMessages(['file' => ['El CSV debe incluir cabecera y al menos una fila de datos.']]);
        }

        $header = str_getcsv($lines[0], ';');
        $headerMap = [];
        foreach ($header as $i => $h) {
            $key = strtolower(trim((string) $h));
            if ($key !== '') {
                $headerMap[$key] = $i;
            }
        }
        if (! isset($headerMap['name'])) {
            throw ValidationException::withMessages(['file' => ['La cabecera debe incluir la columna «name».']]);
        }

        $errors = [];
        $preview = [];
        $createdIds = [];

        for ($idx = 1; $idx < count($lines); $idx++) {
            $lineNo = $idx + 1;
            $line = trim($lines[$idx]);
            if ($line === '') {
                continue;
            }
            $cols = str_getcsv($line, ';');
            $name = $this->cell($cols, $headerMap, 'name');
            if ($name === '') {
                $errors[] = ['line' => $lineNo, 'message' => 'Falta name.'];

                continue;
            }
            $qty = (int) ($this->cell($cols, $headerMap, 'quantity') ?: 1);
            if ($qty < 1 || $qty > 999999) {
                $errors[] = ['line' => $lineNo, 'message' => 'quantity inválida.'];

                continue;
            }

            $row = [
                'name' => $name,
                'quantity' => $qty,
                'asset_type' => $this->cell($cols, $headerMap, 'asset_type'),
                'asset_subtype' => $this->cell($cols, $headerMap, 'asset_subtype'),
                'serial_number' => $this->cell($cols, $headerMap, 'serial_number'),
                'brand' => $this->cell($cols, $headerMap, 'brand'),
                'model' => $this->cell($cols, $headerMap, 'model'),
                'site_label' => $this->cell($cols, $headerMap, 'site_label'),
                'mac_address' => $this->cell($cols, $headerMap, 'mac_address'),
                'warranty_until' => $this->cell($cols, $headerMap, 'warranty_until'),
                'physical_condition' => $this->cell($cols, $headerMap, 'physical_condition'),
                'area_label' => $this->cell($cols, $headerMap, 'area_label'),
                'responsible_name' => $this->cell($cols, $headerMap, 'responsible_name'),
                'responsible_role' => $this->cell($cols, $headerMap, 'responsible_role'),
                'purchase_date' => $this->cell($cols, $headerMap, 'purchase_date'),
                'custody_received_at' => $this->cell($cols, $headerMap, 'custody_received_at'),
                'description' => $this->cell($cols, $headerMap, 'description'),
            ];

            $preview[] = ['line' => $lineNo, 'name' => $name, 'quantity' => $qty];

            if ($dryRun) {
                continue;
            }

            try {
                $lotId = DB::transaction(function () use ($row, $actor, $tenantCompanyId, $request, $qty) {
                    return $this->insertLot($row, $actor, $tenantCompanyId, $request, $qty);
                });
                $createdIds[] = $lotId;
            } catch (\Throwable $e) {
                $errors[] = ['line' => $lineNo, 'message' => $e->getMessage()];
            }
        }

        return ['errors' => $errors, 'created_ids' => $createdIds, 'preview' => $preview];
    }

    /**
     * @param  array<string, string>  $row
     */
    private function insertLot(array $row, User $actor, int $tenantCompanyId, Request $request, int $qty): int
    {
        $serial = trim($row['serial_number'] ?? '');
        $fromDesc = InventoryLotInternalCode::extractFromDescription($row['description'] ?? null);
        $skuAtCreate = $serial !== '' ? $serial : (($fromDesc !== null && $fromDesc !== '') ? $fromDesc : null);
        $macRaw = trim($row['mac_address'] ?? '');
        $macStore = $macRaw !== '' ? $this->normalizeMacAddress($macRaw) : null;
        $fp = $this->fingerprintHashFrom($serial, $macRaw);

        if ($fp !== null && InventoryLot::query()->where('fingerprint_hash', $fp)->exists()) {
            throw new \RuntimeException('Serial/MAC ya registrado en inventario.');
        }

        $warranty = $this->parseDate($row['warranty_until'] ?? null);
        $purchase = $this->parseDate($row['purchase_date'] ?? null);
        $custody = $this->parseDate($row['custody_received_at'] ?? null);

        $lot = InventoryLot::query()->create([
            'owner_user_id' => $actor->id,
            'tenant_company_id' => $tenantCompanyId,
            'sku' => $skuAtCreate,
            'serial_number' => $serial !== '' ? $serial : null,
            'mac_address' => $macStore,
            'fingerprint_hash' => $fp,
            'name' => $row['name'],
            'description' => $row['description'] !== '' ? $row['description'] : null,
            'asset_type' => $row['asset_type'] !== '' ? $row['asset_type'] : null,
            'asset_subtype' => $row['asset_subtype'] !== '' ? $row['asset_subtype'] : null,
            'brand' => $row['brand'] !== '' ? $row['brand'] : null,
            'model' => $row['model'] !== '' ? $row['model'] : null,
            'site_label' => $row['site_label'] !== '' ? $row['site_label'] : null,
            'area_label' => $row['area_label'] !== '' ? $row['area_label'] : null,
            'physical_condition' => $row['physical_condition'] !== '' ? $row['physical_condition'] : null,
            'warranty_until' => $warranty,
            'purchase_date' => $purchase,
            'custody_received_at' => $custody,
            'responsible_name' => $row['responsible_name'] !== '' ? $row['responsible_name'] : null,
            'responsible_role' => $row['responsible_role'] !== '' ? $row['responsible_role'] : null,
            'quantity_available' => $qty,
            'unit_price' => 0,
            'is_active' => true,
            'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
            'lifecycle_status_changed_at' => now(),
            'allow_sale' => false,
            'allow_rental' => false,
        ]);

        if ($lot->sku === null) {
            $this->assignTenantSku($lot, $tenantCompanyId);
        }

        InventoryMovement::query()->create([
            'inventory_lot_id' => $lot->id,
            'user_id' => $actor->id,
            'tenant_company_id' => $tenantCompanyId,
            'type' => InventoryMovement::TYPE_ALTA,
            'quantity_delta' => $lot->quantity_available,
            'inventory_sale_line_id' => null,
            'note' => 'import_csv',
            'created_at' => now(),
        ]);

        // Misma acción que el alta manual: el primer hito de la hoja de vida es el registro en inventario.
        InventoryAuditEvent::query()->create([
            'tenant_company_id' => $tenantCompanyId,
            'entity_type' => 'inventory_lot',
            'entity_id' => (int) $lot->id,
            'action' => 'create',
            'actor_user_id' => $actor->id,
            'request_id' => $request->header('X-Request-Id'),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'before' => null,
            'after' => [
                'name' => $lot->name,
                'inventory_reference' => $lot->sku,
                'serial_number' => $lot->serial_number,
                'mac_address' => $lot->mac_address,
                'lifecycle_status' => $lot->lifecycle_status,
                'quantity_available' => $lot->quantity_available,
                'unit_price' => (string) $lot->unit_price,
                'source' => 'import_csv',
            ],
            'occurred_at' => now(),
        ]);

        return (int) $lot->id;
    }

    private function normalizeMacAddress(?string $raw): ?string
    {
        $value = Str::upper(preg_replace('/[^a-fA-F0-9]/', '', (string) $raw) ?? '');
        if ($value === '') {
            return null;
        }

        return implode(':', str_split(substr($value, 0, 12), 2));
    }

    private function fingerprintHashFrom(?string $serial, ?string $macRaw): ?string
    {
        $serialNorm = Str::upper(trim((string) $serial));
        $macNorm = $this->normalizeMacAddress($macRaw);
        if ($serialNorm === '' && ($macNorm === null || $macNorm === '')) {
            return null;
        }

        return hash('sha256', $serialNorm.'|'.($macNorm ?? ''));
    }

    private function assignTenantSku(InventoryLot $lot, int $tenantCompanyId): void
    {
        $company = \App\Models\Company::query()->find($tenantCompanyId);
        $prefix = 'C'.$tenantCompanyId;
        if ($company && $company->factura_sigla) {
            $clean = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $company->factura_sigla) ?? '');
            if ($clean !== '') {
                $prefix = strlen($clean) > 12 ? substr($clean, 0, 12) : $clean;
            }
        }
        $lot->sku = sprintf('%s-A%06d', $prefix, $lot->id);
        $lot->save();
    }

    private function parseDate(?string $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        try {
            return Carbon::parse($v)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $cols
     * @param  array<string, int>  $headerMap
     */
    private function cell(array $cols, array $headerMap, string $key): string
    {
        if (! isset($headerMap[$key])) {
            return '';
        }
        $i = $headerMap[$key];

        return isset($cols[$i]) ? trim((string) $cols[$i]) : '';
    }
}
