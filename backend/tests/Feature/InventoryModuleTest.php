<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventorySale;
use App\Models\InventorySaleLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_lot_and_movement_alta(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);

        Sanctum::actingAs($admin);

        $r = $this->postJson('/api/inventory/lots', [
            'name' => 'Cable HDMI',
            'sku' => 'HDMI-2M',
            'quantity_available' => 10,
            'unit_price' => '15000.00',
        ]);

        $r->assertCreated();
        $r->assertJsonPath('data.name', 'Cable HDMI');
        $r->assertJsonPath('data.quantity_available', 10);
        $r->assertJsonPath('data.allow_sale', true);
        $r->assertJsonPath('data.allow_rental', false);

        $this->assertDatabaseHas('inventory_lots', [
            'owner_user_id' => $admin->id,
            'name' => 'Cable HDMI',
            'quantity_available' => 10,
        ]);

        $this->assertSame(1, InventoryMovement::query()->where('type', InventoryMovement::TYPE_ALTA)->count());
    }

    public function test_admin_can_set_allow_sale_and_allow_rental_on_create(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);

        Sanctum::actingAs($admin);

        $r = $this->postJson('/api/inventory/lots', [
            'name' => 'Equipo solo alquiler',
            'quantity_available' => 2,
            'unit_price' => '100.00',
            'allow_sale' => false,
            'allow_rental' => true,
        ]);

        $r->assertCreated();
        $r->assertJsonPath('data.allow_sale', false);
        $r->assertJsonPath('data.allow_rental', true);

        $this->assertDatabaseHas('inventory_lots', [
            'owner_user_id' => $admin->id,
            'name' => 'Equipo solo alquiler',
            'allow_sale' => false,
            'allow_rental' => true,
        ]);
    }

    public function test_admin_creates_tenant_lot_with_automatic_sku_when_omitted(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);

        $company = Company::query()->create([
            'nombre' => 'Cliente Inventario Test',
            'factura_sigla' => 'ZYX',
            'estado' => Company::ESTADO_ACTIVO,
            'es_cliente_puntual' => false,
        ]);

        Sanctum::actingAs($admin);

        $r = $this->postJson('/api/inventory/lots', [
            'name' => 'Portátil Dell',
            'quantity_available' => 1,
            'unit_price' => '2500000.00',
            'tenant_company_id' => $company->id,
        ]);

        $r->assertCreated();
        $lotId = (int) $r->json('data.id');
        $this->assertSame(sprintf('ZYX-A%06d', $lotId), $r->json('data.sku'));

        $this->assertDatabaseHas('inventory_lots', [
            'id' => $lotId,
            'tenant_company_id' => $company->id,
            'sku' => sprintf('ZYX-A%06d', $lotId),
        ]);
    }

    public function test_empleado_cannot_register_inventory_sale(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $emp = User::factory()->create(['rol' => User::ROL_EMPLEADO]);

        $lot = InventoryLot::query()->create([
            'owner_user_id' => $admin->id,
            'name' => 'Item',
            'sku' => null,
            'description' => null,
            'quantity_available' => 5,
            'unit_price' => '100.00',
            'is_active' => true,
        ]);

        Sanctum::actingAs($emp);

        $this->postJson('/api/inventory/sales', [
            'lines' => [
                ['inventory_lot_id' => $lot->id, 'quantity' => 2],
            ],
        ])->assertForbidden();

        $lot->refresh();
        $this->assertSame(5, $lot->quantity_available);
    }

    public function test_admin_registers_sale_and_stock_decrements(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);

        $lot = InventoryLot::query()->create([
            'owner_user_id' => $admin->id,
            'name' => 'Item',
            'sku' => null,
            'description' => null,
            'quantity_available' => 5,
            'unit_price' => '100.00',
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);

        $r = $this->postJson('/api/inventory/sales', [
            'lines' => [
                ['inventory_lot_id' => $lot->id, 'quantity' => 2],
            ],
        ]);

        $r->assertOk();
        $r->assertJsonPath('data.total_amount', '200.00');

        $lot->refresh();
        $this->assertSame(3, $lot->quantity_available);

        $line = InventorySaleLine::query()->firstOrFail();
        $this->assertSame($admin->id, (int) $line->owner_user_id);
        $this->assertSame(1, InventoryMovement::query()->where('type', InventoryMovement::TYPE_VENTA)->count());
    }

    public function test_empleado_only_sees_own_sales_in_index(): void
    {
        $e1 = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        $e2 = User::factory()->create(['rol' => User::ROL_EMPLEADO]);

        InventorySale::query()->create([
            'sold_by_user_id' => $e1->id,
            'tenant_company_id' => null,
            'notes' => null,
            'total_amount' => '10.00',
        ]);
        InventorySale::query()->create([
            'sold_by_user_id' => $e2->id,
            'tenant_company_id' => null,
            'notes' => null,
            'total_amount' => '20.00',
        ]);

        Sanctum::actingAs($e1);
        $idx = $this->getJson('/api/inventory/sales');
        $idx->assertOk();
        $this->assertCount(1, $idx->json('data'));
        $this->assertSame('10.00', $idx->json('data.0.total_amount'));
    }

    public function test_owner_accruals_requires_admin(): void
    {
        $emp = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        Sanctum::actingAs($emp);
        $this->getJson('/api/inventory/owner-accruals')->assertForbidden();
    }

    public function test_empleado_internal_lot_index_only_shows_own_titular_lots(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $emp = User::factory()->create(['rol' => User::ROL_EMPLEADO]);

        InventoryLot::query()->create([
            'owner_user_id' => $admin->id,
            'tenant_company_id' => null,
            'name' => 'Router TP-Link',
            'sku' => 'RT-01',
            'description' => null,
            'quantity_available' => 3,
            'unit_price' => '89000.00',
            'is_active' => true,
        ]);

        InventoryLot::query()->create([
            'owner_user_id' => $emp->id,
            'tenant_company_id' => null,
            'name' => 'Cable propio',
            'sku' => 'CB-1',
            'description' => null,
            'quantity_available' => 1,
            'unit_price' => '5000.00',
            'is_active' => true,
        ]);

        Sanctum::actingAs($emp);
        $r = $this->getJson('/api/inventory/lots?active_only=1&tenant_scope=internal&per_page=50');
        $r->assertOk();
        $names = collect($r->json('data'))->pluck('name')->all();
        $this->assertContains('Cable propio', $names);
        $this->assertNotContains('Router TP-Link', $names);
    }
}
