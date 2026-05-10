<?php

namespace Database\Seeders\Concerns;

use App\Models\AppSetting;
use App\Models\InventoryLifecycleTransitionRequest;
use App\Models\InventoryLot;
use App\Models\InventoryLotAttachment;
use App\Models\InventoryMovement;
use App\Models\InventoryRental;
use App\Models\InventoryRentalLine;
use App\Models\InventorySale;
use App\Models\InventorySaleLine;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

trait SeedsInventoryInternalDemo
{
    private const INVENTORY_DEMO_SKU_PREFIX = 'FAKE-LOCAL-';

    private function decimalMul(float|string|int $a, float|string|int $b): string
    {
        return number_format((float) $a * (float) $b, 2, '.', '');
    }

    private function decimalAdd(string $a, string $b): string
    {
        return number_format((float) $a + (float) $b, 2, '.', '');
    }

    protected function seedInventoryLocationsIfMissing(): void
    {
        if (AppSetting::query()->where('key', AppSetting::KEY_INVENTORY_LOCATIONS)->exists()) {
            return;
        }

        AppSetting::setJsonValue(AppSetting::KEY_INVENTORY_LOCATIONS, [
            'Taller',
            'Bodega principal',
            'Oficina administrativa',
            'Mostrador',
        ]);
    }

    /**
     * Inventario interno (tenant_company_id null): lotes, movimientos, ventas y alquileres de prueba.
     *
     * @param  \Illuminate\Support\Collection<int, User>  $techs  Cuatro empleados (propietarios de lote).
     */
    protected function seedInventoryInternalDemoDataset(User $admin, $techs, \Faker\Generator $faker): void
    {
        $this->deletePreviousInventoryDemoLots();

        $t0 = $techs[0];
        $t1 = $techs[1];
        $t2 = $techs[2];
        $t3 = $techs[3];

        $p = self::INVENTORY_DEMO_SKU_PREFIX;

        $lots = [
            $this->createInventoryDemoLot([
                'sku' => $p.'001',
                'owner_user_id' => $t0->id,
                'name' => 'Portátil Dell Latitude (demo inventario)',
                'quantity_available' => 25,
                'unit_price' => 2850000,
                'is_active' => true,
                'serial_number' => 'SN-DMO-001',
                'mac_address' => 'A4:83:E7:12:34:56',
                'asset_type' => 'Computador portátil',
                'asset_subtype' => 'Oficina',
                'brand' => 'Dell',
                'model' => 'Latitude 5420',
                'site_label' => 'Sede central',
                'area_label' => 'Administración',
                'physical_condition' => 'Bueno',
                'warranty_until' => Carbon::now()->addMonths(8)->format('Y-m-d'),
                'purchase_date' => Carbon::now()->subYear()->format('Y-m-d'),
                'custody_received_at' => Carbon::now()->subMonths(10)->format('Y-m-d'),
                'responsible_name' => 'Coordinación TI demo',
                'responsible_role' => 'TI',
                'description' => $this->inventoryDemoLotDescription($faker, '260115-A1B2', 'Dell', 'Oficina administrativa', 'Bueno', 'Equipo portátil estándar para pruebas de listado.'),
            ]),
            $this->createInventoryDemoLot([
                'sku' => $p.'002',
                'owner_user_id' => $t1->id,
                'name' => 'Monitor LG 24" FHD',
                'quantity_available' => 12,
                'unit_price' => 620000,
                'is_active' => true,
                'serial_number' => 'SN-MON-LG-002',
                'asset_type' => 'Monitor',
                'brand' => 'LG',
                'model' => '24MK430H',
                'site_label' => 'Taller',
                'physical_condition' => 'Bueno',
                'description' => $this->inventoryDemoLotDescription($faker, '260115-C3D4', 'LG', 'Taller', 'Bueno', 'Monitores para puestos de diagnóstico.'),
            ]),
            $this->createInventoryDemoLot([
                'sku' => $p.'003',
                'owner_user_id' => $t2->id,
                'name' => 'Router TP-Link AX10',
                'quantity_available' => 10,
                'unit_price' => 189000,
                'is_active' => true,
                'allow_sale' => true,
                'allow_rental' => true,
                'description' => $this->inventoryDemoLotDescription($faker, '260116-E5F6', 'TP-Link', 'Bodega principal', 'Nuevo', 'Stock para alquiler de demostración.'),
            ]),
            $this->createInventoryDemoLot([
                'sku' => $p.'004',
                'owner_user_id' => $t3->id,
                'name' => 'Kit cables Ethernet Cat6 (5 unidades)',
                'quantity_available' => 50,
                'unit_price' => 45000,
                'is_active' => true,
                'description' => $this->inventoryDemoLotDescription($faker, '260116-G7H8', 'Generico', 'Bodega principal', 'Nuevo', 'Paquetes para venta mostrador.'),
            ]),
            $this->createInventoryDemoLot([
                'sku' => $p.'005',
                'owner_user_id' => $admin->id,
                'name' => 'Servidor NAS Synology (almacén general)',
                'quantity_available' => 3,
                'unit_price' => 4200000,
                'is_active' => true,
                'description' => $this->inventoryDemoLotDescription($faker, 'ALM-'.$faker->numerify('####'), 'Synology', 'Bodega principal', 'Bueno', 'Reserva administrativa; no asignado a técnico.'),
            ]),
            $this->createInventoryDemoLot([
                'sku' => $p.'006',
                'owner_user_id' => $t0->id,
                'name' => 'PC escritorio HP Elite (dado de baja)',
                'quantity_available' => 0,
                'unit_price' => 800000,
                'is_active' => false,
                'allow_sale' => false,
                'allow_rental' => false,
                'lifecycle_status' => InventoryLot::LIFECYCLE_BAJA,
                'lifecycle_status_changed_at' => Carbon::now()->subMonths(6),
                'decommission_reason' => 'Obsolescencia — retiro de parque (demo seeder).',
                'decommissioned_at' => Carbon::now()->subMonths(6),
                'decommissioned_by_user_id' => $admin->id,
                'serial_number' => 'SN-HP-006-RET',
                'asset_type' => 'PC de escritorio',
                'brand' => 'HP',
                'model' => 'EliteDesk 800',
                'description' => $this->inventoryDemoLotDescription($faker, '240901-X9Y0', 'HP', 'Archivo', 'Fuera de servicio', 'Retirado por obsolescencia; solo para pestaña Bajas.'),
            ]),
            $this->createInventoryDemoLot([
                'sku' => $p.'007',
                'owner_user_id' => $t1->id,
                'name' => 'Impresora láser Samsung (baja)',
                'quantity_available' => 1,
                'unit_price' => 350000,
                'is_active' => false,
                'allow_sale' => false,
                'allow_rental' => false,
                'lifecycle_status' => InventoryLot::LIFECYCLE_BAJA,
                'lifecycle_status_changed_at' => Carbon::now()->subMonths(3),
                'decommission_reason' => 'Daño irreparable de fusor (demo seeder).',
                'decommissioned_at' => Carbon::now()->subMonths(3),
                'decommissioned_by_user_id' => $admin->id,
                'serial_number' => 'SN-SAM-PRN-007',
                'asset_type' => 'Impresora',
                'brand' => 'Samsung',
                'description' => $this->inventoryDemoLotDescription($faker, '250210-M1N2', 'Samsung', 'Taller', 'Fuera de servicio', 'Pieza no reparable; stock congelado.'),
            ]),
            $this->createInventoryDemoLot([
                'sku' => $p.'008',
                'owner_user_id' => $t2->id,
                'name' => 'Soldador estación Yihua',
                'quantity_available' => 4,
                'unit_price' => 280000,
                'is_active' => true,
                'allow_sale' => false,
                'allow_rental' => false,
                'lifecycle_status' => InventoryLot::LIFECYCLE_REPARACION,
                'lifecycle_status_changed_at' => Carbon::now()->subDays(4),
                'repair_reason' => 'Punta desgastada y temperatura inestable (demo).',
                'serial_number' => 'SN-YIHUA-008',
                'asset_type' => 'Herramienta',
                'brand' => 'Yihua',
                'model' => '878D',
                'site_label' => 'Taller',
                'description' => $this->inventoryDemoLotDescription($faker, '260201-P3Q4', 'Yihua', 'Taller', 'Requiere mantenimiento', 'Revisión de punta y calibración pendiente.'),
            ]),
            $this->createInventoryDemoLot([
                'sku' => $p.'009',
                'owner_user_id' => $t3->id,
                'name' => 'UPS APC 600VA',
                'quantity_available' => 2,
                'unit_price' => 510000,
                'is_active' => true,
                'description' => $this->inventoryDemoLotDescription($faker, '260202-R5S6', 'APC', 'Oficina administrativa', 'Fuera de servicio', 'Batería agotada; etiqueta reparación.'),
            ]),
            $this->createInventoryDemoLot([
                'sku' => $p.'010',
                'owner_user_id' => $admin->id,
                'name' => 'Switch Cisco 8 puertos (histórico alquiler)',
                'quantity_available' => 20,
                'unit_price' => 320000,
                'is_active' => true,
                'allow_sale' => true,
                'allow_rental' => true,
                'description' => $this->inventoryDemoLotDescription($faker, '260118-T7U8', 'Cisco', 'Bodega principal', 'Bueno', 'Usado en alquiler cerrado de prueba; stock restaurado.'),
            ]),
            $this->createInventoryDemoLot([
                'sku' => $p.'011',
                'owner_user_id' => $t0->id,
                'name' => 'Pasta térmica Arctic MX-4',
                'quantity_available' => 100,
                'unit_price' => 28000,
                'is_active' => true,
                'asset_type' => 'Consumible',
                'brand' => 'Arctic',
                'model' => 'MX-4 4g',
                'description' => $this->inventoryDemoLotDescription($faker, '260119-V9W0', 'Arctic', 'Taller', 'Nuevo', 'Consumible para ventas de volumen.'),
            ]),
            $this->createInventoryDemoLot([
                'sku' => $p.'012',
                'owner_user_id' => $t1->id,
                'name' => 'Estación de trabajo CAD (reparación)',
                'quantity_available' => 1,
                'unit_price' => 5200000,
                'is_active' => true,
                'allow_sale' => false,
                'allow_rental' => false,
                'lifecycle_status' => InventoryLot::LIFECYCLE_REPARACION,
                'lifecycle_status_changed_at' => Carbon::now()->subDays(9),
                'repair_reason' => 'GPU con artefactos bajo carga; pendiente diagnóstico profundo.',
                'serial_number' => 'SN-CAD-WS-012',
                'asset_type' => 'Workstation',
                'brand' => 'Lenovo',
                'model' => 'ThinkStation P350',
                'site_label' => 'Diseño',
                'physical_condition' => 'Regular',
                'description' => 'Equipo en taller para revisión de placa (demo seeder).',
            ]),
            $this->createInventoryDemoLot([
                'sku' => $p.'013',
                'owner_user_id' => $t2->id,
                'name' => 'Tablet Samsung (vendida — stock cero)',
                'quantity_available' => 0,
                'unit_price' => 890000,
                'is_active' => false,
                'allow_sale' => false,
                'allow_rental' => false,
                'lifecycle_status' => InventoryLot::LIFECYCLE_VENDIDO,
                'lifecycle_status_changed_at' => Carbon::now()->subDays(20),
                'serial_number' => 'SN-TAB-SAM-013',
                'asset_type' => 'Tablet',
                'brand' => 'Samsung',
                'model' => 'Galaxy Tab S9',
                'description' => 'Lote agotado por venta total; estado lifecycle vendido (demo).',
            ]),
            $this->createInventoryDemoLot([
                'sku' => $p.'014',
                'owner_user_id' => $t3->id,
                'name' => 'Firewall Fortinet (custodia extendida)',
                'quantity_available' => 2,
                'unit_price' => 2100000,
                'is_active' => true,
                'serial_number' => 'SN-FTNT-FW-014',
                'mac_address' => '00:0C:29:AB:CD:EF',
                'asset_type' => 'Seguridad perimetral',
                'asset_subtype' => 'Firewall',
                'brand' => 'Fortinet',
                'model' => 'FortiGate 60F',
                'site_label' => 'Cuarto de equipos',
                'area_label' => 'Red WAN',
                'physical_condition' => 'Bueno',
                'warranty_until' => Carbon::now()->addYear()->format('Y-m-d'),
                'purchase_date' => Carbon::now()->subMonths(14)->format('Y-m-d'),
                'custody_received_at' => Carbon::now()->subMonths(14)->format('Y-m-d'),
                'responsible_name' => 'Ing. Red Demo',
                'responsible_role' => 'Infraestructura',
                'description' => 'Activo con ficha de custodia completa para pruebas de detalle en UI.',
            ]),
        ];

        $bySku = collect($lots)->keyBy('sku');

        $this->seedInventoryDemoSale($admin, 'Venta demo inventario — cliente corporativo', [
            ['lot' => $bySku[$p.'001'], 'qty' => 1],
            ['lot' => $bySku[$p.'004'], 'qty' => 3],
        ], Carbon::now()->subDays(3));

        $this->seedInventoryDemoSale($t0, 'Venta mostrador demo — técnico 1', [
            ['lot' => $bySku[$p.'002'], 'qty' => 1],
        ], Carbon::now()->subDays(1));

        $this->seedInventoryDemoSale($admin, 'Venta consumibles demo', [
            ['lot' => $bySku[$p.'011'], 'qty' => 5],
        ], Carbon::now()->subHours(6));

        $router = $bySku[$p.'003'];
        DB::transaction(function () use ($admin, $router) {
            $qty = 2;
            $rental = InventoryRental::query()->create([
                'created_by_user_id' => $admin->id,
                'tenant_company_id' => null,
                'status' => InventoryRental::STATUS_ACTIVE,
                'customer_name' => 'Cliente Alquiler Activo Demo',
                'customer_phone' => '3005557788',
                'notes' => 'Alquiler demo — router para evento; pendiente cierre.',
                'started_at' => Carbon::now()->subDays(2),
                'closed_at' => null,
            ]);
            $unit = (string) $router->unit_price;
            $lineTotal = $this->decimalMul($unit, $qty);
            InventoryRentalLine::query()->create([
                'inventory_rental_id' => $rental->id,
                'inventory_lot_id' => $router->id,
                'owner_user_id' => $router->owner_user_id,
                'tenant_company_id' => null,
                'quantity' => $qty,
                'rental_days' => 1,
                'unit_price' => $unit,
                'line_total' => $lineTotal,
                'returned_at' => null,
            ]);
            $router->quantity_available = $router->quantity_available - $qty;
            $router->save();
            InventoryMovement::query()->create([
                'inventory_lot_id' => $router->id,
                'user_id' => $admin->id,
                'tenant_company_id' => null,
                'type' => InventoryMovement::TYPE_ALQUILER_SALIDA,
                'quantity_delta' => -$qty,
                'inventory_sale_line_id' => null,
                'note' => 'Salida por alquiler #'.$rental->id.' (demo inventario)',
                'created_at' => Carbon::now()->subDays(2),
            ]);
        });

        $switch = $bySku[$p.'010'];
        DB::transaction(function () use ($admin, $switch) {
            $qty = 1;
            $started = Carbon::now()->subDays(18);
            $closed = Carbon::now()->subDays(5);
            $rental = InventoryRental::query()->create([
                'created_by_user_id' => $admin->id,
                'tenant_company_id' => null,
                'status' => InventoryRental::STATUS_CLOSED,
                'customer_name' => 'Cliente Alquiler Cerrado Demo',
                'customer_phone' => '3016669900',
                'notes' => 'Alquiler demo cerrado; equipos devueltos.',
                'started_at' => $started,
                'closed_at' => $closed,
            ]);
            $unit = (string) $switch->unit_price;
            $lineTotal = $this->decimalMul($unit, $qty);
            InventoryRentalLine::query()->create([
                'inventory_rental_id' => $rental->id,
                'inventory_lot_id' => $switch->id,
                'owner_user_id' => $switch->owner_user_id,
                'tenant_company_id' => null,
                'quantity' => $qty,
                'rental_days' => 1,
                'unit_price' => $unit,
                'line_total' => $lineTotal,
                'returned_at' => $closed,
            ]);
            InventoryMovement::query()->create([
                'inventory_lot_id' => $switch->id,
                'user_id' => $admin->id,
                'tenant_company_id' => null,
                'type' => InventoryMovement::TYPE_ALQUILER_SALIDA,
                'quantity_delta' => -$qty,
                'inventory_sale_line_id' => null,
                'note' => 'Salida por alquiler #'.$rental->id.' (demo inventario)',
                'created_at' => $started,
            ]);
            InventoryMovement::query()->create([
                'inventory_lot_id' => $switch->id,
                'user_id' => $admin->id,
                'tenant_company_id' => null,
                'type' => InventoryMovement::TYPE_ALQUILER_DEVOLUCION,
                'quantity_delta' => $qty,
                'inventory_sale_line_id' => null,
                'note' => 'Devolución alquiler #'.$rental->id.' (demo inventario)',
                'created_at' => $closed,
            ]);
        });

        $nas = $bySku[$p.'005'];
        InventoryMovement::query()->create([
            'inventory_lot_id' => $nas->id,
            'user_id' => $admin->id,
            'tenant_company_id' => null,
            'type' => InventoryMovement::TYPE_ALTA,
            'quantity_delta' => 3,
            'inventory_sale_line_id' => null,
            'note' => 'Alta inicial demo inventario (NAS)',
            'created_at' => Carbon::now()->subDays(30),
        ]);

        $this->seedInventoryDemoLifecycleRequestsAndAttachments($admin, $t0, $bySku, $p);
    }

    /**
     * Solicitud de transición pendiente + adjunto mínimo en disco público (pruebas de adjuntos / hoja de vida).
     */
    private function seedInventoryDemoLifecycleRequestsAndAttachments(User $admin, User $requestedBy, $bySku, string $p): void
    {
        $ups = $bySku[$p.'009'];

        InventoryLifecycleTransitionRequest::query()->updateOrCreate(
            [
                'inventory_lot_id' => $ups->id,
                'status' => InventoryLifecycleTransitionRequest::STATUS_PENDING,
                'target_status' => InventoryLifecycleTransitionRequest::TARGET_BAJA,
            ],
            [
                'tenant_company_id' => null,
                'requested_by_user_id' => $requestedBy->id,
                'reason' => 'Solicitud demo: retiro por fin de vida útil de baterías (seeder).',
                'required_approvals' => 2,
                'resolved_at' => null,
                'resolved_by_user_id' => null,
                'resolution_note' => null,
            ]
        );

        $laptop = $bySku[$p.'001'];
        $disk = Storage::disk('public');
        $relative = 'inventory_lot_attachments/demo-seed/'.$laptop->id.'/ficha-demo.txt';
        if (! $disk->exists($relative)) {
            $disk->put($relative, "Ficha técnica de prueba (generada por InventoryInternalDemoSeeder).\nLote ID: {$laptop->id}\n");
        }

        InventoryLotAttachment::query()->updateOrCreate(
            [
                'inventory_lot_id' => $laptop->id,
                'original_filename' => 'ficha-demo.txt',
            ],
            [
                'path' => $relative,
                'mime_type' => 'text/plain',
                'size_bytes' => strlen($disk->get($relative)),
                'uploaded_by_user_id' => $admin->id,
                'inventory_audit_event_id' => null,
            ]
        );
    }

    private function deletePreviousInventoryDemoLots(): void
    {
        $lotIds = InventoryLot::query()
            ->where('sku', 'like', self::INVENTORY_DEMO_SKU_PREFIX.'%')
            ->pluck('id');
        if ($lotIds->isEmpty()) {
            return;
        }

        InventoryMovement::query()->whereIn('inventory_lot_id', $lotIds)->delete();

        $saleIds = InventorySaleLine::query()->whereIn('inventory_lot_id', $lotIds)->pluck('inventory_sale_id')->unique()->filter();
        if ($saleIds->isNotEmpty()) {
            InventorySaleLine::query()->whereIn('inventory_sale_id', $saleIds)->delete();
            InventorySale::query()->whereIn('id', $saleIds)->delete();
        }

        $rentalIds = InventoryRentalLine::query()->whereIn('inventory_lot_id', $lotIds)->pluck('inventory_rental_id')->unique()->filter();
        if ($rentalIds->isNotEmpty()) {
            InventoryRentalLine::query()->whereIn('inventory_rental_id', $rentalIds)->delete();
            InventoryRental::query()->whereIn('id', $rentalIds)->delete();
        }

        InventoryLot::query()->whereIn('id', $lotIds)->delete();
    }

    private function inventoryDemoLotDescription(\Faker\Generator $faker, string $codigoInterno, string $marca, string $ubicacion, string $estadoFisico, string $notaLibre): string
    {
        $lines = [
            "Codigo interno: {$codigoInterno}",
            "Marca: {$marca}",
            "Ubicacion: {$ubicacion}",
            "Estado fisico: {$estadoFisico}",
            $notaLibre,
        ];

        return implode("\n", array_filter($lines));
    }

    private function createInventoryDemoLot(array $attrs): InventoryLot
    {
        $defaults = [
            'tenant_company_id' => null,
            'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
            'lifecycle_status_changed_at' => null,
            'repair_reason' => null,
            'repair_resolution' => null,
            'decommission_reason' => null,
            'decommissioned_at' => null,
            'decommissioned_by_user_id' => null,
            'allow_sale' => true,
            'allow_rental' => false,
            'serial_number' => null,
            'mac_address' => null,
            'asset_type' => null,
            'asset_subtype' => null,
            'brand' => null,
            'model' => null,
            'site_label' => null,
            'area_label' => null,
            'physical_condition' => null,
            'warranty_until' => null,
            'purchase_date' => null,
            'custody_received_at' => null,
            'responsible_name' => null,
            'responsible_role' => null,
        ];

        $attrs['tenant_company_id'] = null;

        return InventoryLot::query()->create(array_merge($defaults, $attrs));
    }

    /**
     * @param  array<int, array{lot: InventoryLot, qty: int}>  $lines
     */
    private function seedInventoryDemoSale(User $soldBy, string $notes, array $lines, Carbon $createdAt): void
    {
        DB::transaction(function () use ($soldBy, $notes, $lines, $createdAt) {
            $total = '0.00';
            foreach ($lines as $row) {
                /** @var InventoryLot $lot */
                $lot = $row['lot'];
                $qty = (int) $row['qty'];
                $unit = (string) $lot->unit_price;
                $total = $this->decimalAdd($total, $this->decimalMul($unit, $qty));
            }

            $sale = InventorySale::query()->create([
                'sold_by_user_id' => $soldBy->id,
                'tenant_company_id' => null,
                'notes' => $notes,
                'total_amount' => $total,
            ]);
            InventorySale::query()->whereKey($sale->id)->update([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            foreach ($lines as $row) {
                $lot = $row['lot'];
                $qty = (int) $row['qty'];
                $unit = (string) $lot->unit_price;
                $lineTotal = $this->decimalMul($unit, $qty);

                if ($lot->quantity_available < $qty) {
                    throw new \RuntimeException("Stock insuficiente en lote {$lot->sku} para seeder (disponible {$lot->quantity_available}, pedido {$qty}).");
                }

                $line = InventorySaleLine::query()->create([
                    'inventory_sale_id' => $sale->id,
                    'inventory_lot_id' => $lot->id,
                    'owner_user_id' => $lot->owner_user_id,
                    'tenant_company_id' => null,
                    'quantity' => $qty,
                    'unit_price' => $unit,
                    'line_total' => $lineTotal,
                ]);
                InventorySaleLine::query()->whereKey($line->id)->update([
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);

                InventoryMovement::query()->create([
                    'inventory_lot_id' => $lot->id,
                    'user_id' => $soldBy->id,
                    'tenant_company_id' => null,
                    'type' => InventoryMovement::TYPE_VENTA,
                    'quantity_delta' => -$qty,
                    'inventory_sale_line_id' => $line->id,
                    'note' => 'Venta #'.$sale->id.' (demo inventario)',
                    'created_at' => $createdAt,
                ]);

                $lot->quantity_available = $lot->quantity_available - $qty;
                $lot->save();
            }
        });
    }
}
