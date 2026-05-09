<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\InventoryLot;
use App\Models\InventoryLotAttachment;
use App\Models\InventoryMovement;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Datos demo para custodia por empresa (portal NIT+OTP) y servicios vinculados a lote interno.
 *
 * Requiere: `DatabaseSeeder` (empresa SYF con correo), `InventoryInternalDemoSeeder` (lote FAKE-LOCAL-001).
 */
class CompanyCustodyAndMaintenanceDemoSeeder extends Seeder
{
    private const TENANT_LOT_SKU_PREFIX = 'FAKE-TENANT-DEMO-';

    public function run(): void
    {
        $company = Company::query()->where('factura_sigla', 'SYF')->first();
        $admin = User::query()->where('correo', 'admin@hbm.local')->first();
        $tech = User::query()->where('correo', 'tc1@hbm.local')->first();
        $internalLot = InventoryLot::query()->where('sku', 'FAKE-LOCAL-001')->first();

        if ($company === null || $admin === null || $tech === null || $internalLot === null) {
            if ($this->command !== null) {
                $this->command->warn('CompanyCustodyAndMaintenanceDemoSeeder: faltan SYF, admin@hbm.local, tc1@hbm.local o lote FAKE-LOCAL-001. Ejecute DatabaseSeeder e InventoryInternalDemoSeeder antes.');
            }

            return;
        }

        $this->deletePreviousTenantDemoLots();

        InventoryLot::query()->create([
            'owner_user_id' => $admin->id,
            'tenant_company_id' => $company->id,
            'sku' => self::TENANT_LOT_SKU_PREFIX.'SW01',
            'serial_number' => 'SN-SYF-SW-01',
            'name' => 'Switch capa 2 — custodia SYF',
            'description' => 'Equipo en custodia del cliente (demo portal inventario).',
            'asset_type' => 'Switch',
            'brand' => 'Ubiquiti',
            'model' => 'UniFi Switch 24',
            'site_label' => 'Sede principal SYF',
            'area_label' => 'Red LAN',
            'physical_condition' => 'Bueno',
            'warranty_until' => Carbon::now()->addMonths(18)->format('Y-m-d'),
            'purchase_date' => Carbon::now()->subMonths(8)->format('Y-m-d'),
            'custody_received_at' => Carbon::now()->subMonths(7)->format('Y-m-d'),
            'responsible_name' => 'María Custodia Demo',
            'responsible_role' => 'TI cliente',
            'quantity_available' => 1,
            'unit_price' => '450000.00',
            'is_active' => true,
            'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
            'allow_sale' => false,
            'allow_rental' => false,
        ]);

        InventoryLot::query()->create([
            'owner_user_id' => $admin->id,
            'tenant_company_id' => $company->id,
            'sku' => self::TENANT_LOT_SKU_PREFIX.'AP01',
            'serial_number' => 'SN-SYF-AP-01',
            'name' => 'Access point Wi‑Fi — custodia SYF',
            'description' => 'Punto de acceso en sala de ventas (demo).',
            'asset_type' => 'Access point',
            'brand' => 'Ubiquiti',
            'model' => 'U6 Lite',
            'site_label' => 'Sala de ventas',
            'physical_condition' => 'Bueno',
            'warranty_until' => Carbon::now()->addDays(25)->format('Y-m-d'),
            'purchase_date' => Carbon::now()->subYear()->format('Y-m-d'),
            'custody_received_at' => Carbon::now()->subYear()->format('Y-m-d'),
            'responsible_name' => 'Carlos Redes',
            'responsible_role' => 'Operaciones',
            'quantity_available' => 2,
            'unit_price' => '320000.00',
            'is_active' => true,
            'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
            'allow_sale' => false,
            'allow_rental' => false,
        ]);

        Service::query()->updateOrCreate(
            ['code' => 'DEMO-MAINT-SYF-01'],
            [
                'company_id' => $company->id,
                'inventory_lot_id' => $internalLot->id,
                'user_id' => $tech->id,
                'assigned_by_user_id' => $admin->id,
                'catalog_id' => null,
                'kind' => Service::KIND_MANTENIMIENTO,
                'client_name' => $company->nombre,
                'client_telefono' => $company->telefono ?? '6015550000',
                'service_type' => 'Mantenimiento preventivo',
                'description' => 'Mantenimiento vinculado al lote interno FAKE-LOCAL-001 (demo seeder).',
                'amount' => 185000,
                'service_date' => Carbon::now()->subDays(2)->format('Y-m-d'),
                'status' => Service::STATUS_ACTIVO,
                'assignment_status' => null,
            ]
        );

        Service::query()->updateOrCreate(
            ['code' => 'DEMO-SVC-LOT-SYF-01'],
            [
                'company_id' => $company->id,
                'inventory_lot_id' => $internalLot->id,
                'user_id' => $tech->id,
                'assigned_by_user_id' => $admin->id,
                'catalog_id' => null,
                'kind' => Service::KIND_SERVICIO,
                'client_name' => $company->nombre,
                'client_telefono' => $company->telefono ?? '6015550000',
                'service_type' => 'Revisión de equipo',
                'description' => 'Servicio estándar con referencia a lote interno (demo).',
                'amount' => 95000,
                'service_date' => Carbon::now()->subDays(5)->format('Y-m-d'),
                'status' => Service::STATUS_ACTIVO,
                'assignment_status' => null,
            ]
        );
    }

    private function deletePreviousTenantDemoLots(): void
    {
        $lotIds = InventoryLot::query()
            ->where('sku', 'like', self::TENANT_LOT_SKU_PREFIX.'%')
            ->pluck('id');

        if ($lotIds->isEmpty()) {
            return;
        }

        InventoryMovement::query()->whereIn('inventory_lot_id', $lotIds)->delete();
        InventoryLotAttachment::query()->whereIn('inventory_lot_id', $lotIds)->delete();

        InventoryLot::query()->whereIn('id', $lotIds)->delete();
    }
}
