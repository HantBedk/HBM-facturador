<?php

namespace App\Services\Spreadsheet;

use App\Models\Company;
use App\Models\InventoryLot;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;

final class SpreadsheetRowParser
{
    use SpreadsheetCellTrait;

    /**
     * Parse and validate a raw row into a typed payload.
     *
     * @param  list<mixed>       $row
     * @param  array<string,int> $map
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
     *
     * @throws \InvalidArgumentException
     */
    public function parse(array $row, array $map, User $actor): array
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
}
