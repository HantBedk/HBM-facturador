<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\InventoryAuditEvent;
use App\Models\InventoryLot;
use App\Models\InventoryLotAttachment;
use App\Models\InventoryMovement;
use App\Models\InventorySale;
use App\Models\InventorySaleLine;
use App\Models\User;
use App\Support\InventoryLotWarrantyLabels;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
            'serial_number' => 'HDMI-2M',
            'quantity_available' => 10,
            'unit_price' => '15000.00',
        ]);

        $r->assertCreated();
        $r->assertJsonPath('data.name', 'Cable HDMI');
        $r->assertJsonPath('data.quantity_available', 10);
        $r->assertJsonPath('data.allow_sale', true);
        $r->assertJsonPath('data.allow_rental', false);
        $r->assertJsonPath('data.internal_code', 'HDMI-2M');

        $this->assertDatabaseHas('inventory_lots', [
            'owner_user_id' => $admin->id,
            'name' => 'Cable HDMI',
            'sku' => 'HDMI-2M',
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

    public function test_admin_creates_tenant_lot_with_automatic_internal_code_when_omitted(): void
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
        $this->assertSame(sprintf('ZYX-A%06d', $lotId), $r->json('data.internal_code'));

        $this->assertDatabaseHas('inventory_lots', [
            'id' => $lotId,
            'tenant_company_id' => $company->id,
            'sku' => sprintf('ZYX-A%06d', $lotId),
            'allow_sale' => false,
            'allow_rental' => false,
        ]);
    }

    public function test_tenant_lot_cannot_enable_commercial_flags_on_update(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $company = Company::query()->create([
            'nombre' => 'Cliente Custodia',
            'factura_sigla' => 'CUS',
            'estado' => Company::ESTADO_ACTIVO,
            'es_cliente_puntual' => false,
        ]);

        $lot = InventoryLot::query()->create([
            'owner_user_id' => $admin->id,
            'tenant_company_id' => $company->id,
            'name' => 'Laptop custodia',
            'sku' => 'CUS-A000001',
            'description' => null,
            'quantity_available' => 1,
            'unit_price' => '2500000.00',
            'is_active' => true,
            'allow_sale' => false,
            'allow_rental' => false,
        ]);

        Sanctum::actingAs($admin);

        $this->patchJson('/api/inventory/lots/'.$lot->id.'?tenant_company_id='.$company->id, [
            'allow_sale' => true,
            'allow_rental' => true,
        ])->assertOk();

        $lot->refresh();
        $this->assertFalse((bool) $lot->allow_sale);
        $this->assertFalse((bool) $lot->allow_rental);
    }

    public function test_tenant_lot_cannot_be_sold_or_rented_by_api(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $company = Company::query()->create([
            'nombre' => 'Cliente No Comercial',
            'factura_sigla' => 'NOC',
            'estado' => Company::ESTADO_ACTIVO,
            'es_cliente_puntual' => false,
        ]);

        $lot = InventoryLot::query()->create([
            'owner_user_id' => $admin->id,
            'tenant_company_id' => $company->id,
            'name' => 'Router cliente',
            'sku' => 'NOC-A000001',
            'description' => null,
            'quantity_available' => 3,
            'unit_price' => '120000.00',
            'is_active' => true,
            'allow_sale' => false,
            'allow_rental' => false,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/inventory/sales', [
            'tenant_company_id' => $company->id,
            'lines' => [
                ['inventory_lot_id' => $lot->id, 'quantity' => 1],
            ],
        ])->assertUnprocessable();

        $this->postJson('/api/inventory/rentals', [
            'tenant_company_id' => $company->id,
            'lines' => [
                ['inventory_lot_id' => $lot->id, 'quantity' => 1],
            ],
        ])->assertUnprocessable();
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

    public function test_full_sale_marks_lot_vendido_when_stock_reaches_zero(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);

        $lot = InventoryLot::query()->create([
            'owner_user_id' => $admin->id,
            'name' => 'Item agotado',
            'sku' => 'REF-DEPL',
            'description' => null,
            'quantity_available' => 2,
            'unit_price' => '50.00',
            'is_active' => true,
            'allow_sale' => true,
            'allow_rental' => true,
            'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/inventory/sales', [
            'lines' => [
                ['inventory_lot_id' => $lot->id, 'quantity' => 2],
            ],
        ])->assertOk();

        $lot->refresh();
        $this->assertSame(0, $lot->quantity_available);
        $this->assertFalse($lot->is_active);
        $this->assertFalse($lot->allow_sale);
        $this->assertFalse($lot->allow_rental);
        $this->assertSame(InventoryLot::LIFECYCLE_VENDIDO, $lot->lifecycle_status);
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

    public function test_admin_can_view_lot_detail_scoped_by_tenant_company(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $companyA = Company::query()->create([
            'nombre' => 'Empresa A',
            'factura_sigla' => 'EDA',
            'estado' => Company::ESTADO_ACTIVO,
            'es_cliente_puntual' => false,
        ]);
        $companyB = Company::query()->create([
            'nombre' => 'Empresa B',
            'factura_sigla' => 'EDB',
            'estado' => Company::ESTADO_ACTIVO,
            'es_cliente_puntual' => false,
        ]);
        $lotB = InventoryLot::query()->create([
            'owner_user_id' => $admin->id,
            'tenant_company_id' => $companyB->id,
            'name' => 'Activo B',
            'sku' => 'EDB-A000001',
            'description' => null,
            'quantity_available' => 1,
            'unit_price' => '10.00',
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/inventory/lots/'.$lotB->id.'?tenant_company_id='.$companyA->id)
            ->assertForbidden();
        $this->getJson('/api/inventory/lots/'.$lotB->id.'?tenant_company_id='.$companyB->id)
            ->assertOk()
            ->assertJsonPath('data.id', $lotB->id);
    }

    public function test_empleado_cannot_view_lot_detail_if_not_owner(): void
    {
        $owner = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        $other = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        $lot = InventoryLot::query()->create([
            'owner_user_id' => $owner->id,
            'tenant_company_id' => null,
            'name' => 'Activo privado',
            'sku' => 'PRV-001',
            'description' => null,
            'quantity_available' => 1,
            'unit_price' => '50.00',
            'is_active' => true,
            'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
        ]);

        Sanctum::actingAs($other);
        $this->getJson('/api/inventory/lots/'.$lot->id.'?tenant_scope=internal')
            ->assertForbidden();
    }

    public function test_empleado_can_view_custody_lot_detail_for_maintenance(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $emp = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        $company = Company::query()->create([
            'nombre' => 'Empresa Det Mantto',
            'factura_sigla' => 'EDM',
            'estado' => Company::ESTADO_ACTIVO,
            'es_cliente_puntual' => false,
        ]);
        $lot = InventoryLot::query()->create([
            'owner_user_id' => $admin->id,
            'tenant_company_id' => $company->id,
            'name' => 'Firewall',
            'sku' => 'EDM-A000001',
            'description' => null,
            'quantity_available' => 1,
            'unit_price' => '0.00',
            'is_active' => true,
            'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
        ]);

        Sanctum::actingAs($emp);

        $this->getJson(
            '/api/inventory/lots/'.$lot->id.'?tenant_company_id='.$company->id.'&for_maintenance=1'
        )
            ->assertOk()
            ->assertJsonPath('data.id', $lot->id);
    }

    public function test_admin_inventory_movements_scope_by_tenant_company(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $companyA = Company::query()->create([
            'nombre' => 'Empresa MA',
            'factura_sigla' => 'MVA',
            'estado' => Company::ESTADO_ACTIVO,
            'es_cliente_puntual' => false,
        ]);
        $companyB = Company::query()->create([
            'nombre' => 'Empresa MB',
            'factura_sigla' => 'MVB',
            'estado' => Company::ESTADO_ACTIVO,
            'es_cliente_puntual' => false,
        ]);

        $lotA = InventoryLot::query()->create([
            'owner_user_id' => $admin->id,
            'tenant_company_id' => $companyA->id,
            'name' => 'Equipo A',
            'sku' => 'MVA-A000001',
            'description' => null,
            'quantity_available' => 1,
            'unit_price' => '10.00',
            'is_active' => true,
        ]);
        $lotB = InventoryLot::query()->create([
            'owner_user_id' => $admin->id,
            'tenant_company_id' => $companyB->id,
            'name' => 'Equipo B',
            'sku' => 'MVB-A000001',
            'description' => null,
            'quantity_available' => 1,
            'unit_price' => '20.00',
            'is_active' => true,
        ]);

        InventoryMovement::query()->create([
            'inventory_lot_id' => $lotA->id,
            'user_id' => $admin->id,
            'tenant_company_id' => $companyA->id,
            'type' => InventoryMovement::TYPE_AJUSTE,
            'quantity_delta' => 1,
            'inventory_sale_line_id' => null,
            'note' => 'Ajuste A',
            'created_at' => now(),
        ]);
        InventoryMovement::query()->create([
            'inventory_lot_id' => $lotB->id,
            'user_id' => $admin->id,
            'tenant_company_id' => $companyB->id,
            'type' => InventoryMovement::TYPE_AJUSTE,
            'quantity_delta' => 1,
            'inventory_sale_line_id' => null,
            'note' => 'Ajuste B',
            'created_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $res = $this->getJson('/api/inventory/movements?tenant_company_id='.$companyA->id.'&per_page=50')
            ->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame($lotA->id, (int) $res->json('data.0.inventory_lot_id'));
    }

    public function test_empleado_inventory_movements_only_for_owned_active_lots(): void
    {
        $owner = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        $other = User::factory()->create(['rol' => User::ROL_EMPLEADO]);

        $lotOwner = InventoryLot::query()->create([
            'owner_user_id' => $owner->id,
            'tenant_company_id' => null,
            'name' => 'Equipo propio',
            'sku' => 'OWN-001',
            'description' => null,
            'quantity_available' => 1,
            'unit_price' => '10.00',
            'is_active' => true,
            'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
        ]);
        $lotOther = InventoryLot::query()->create([
            'owner_user_id' => $other->id,
            'tenant_company_id' => null,
            'name' => 'Equipo ajeno',
            'sku' => 'OTH-001',
            'description' => null,
            'quantity_available' => 1,
            'unit_price' => '10.00',
            'is_active' => true,
            'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
        ]);

        InventoryMovement::query()->create([
            'inventory_lot_id' => $lotOwner->id,
            'user_id' => $owner->id,
            'tenant_company_id' => null,
            'type' => InventoryMovement::TYPE_AJUSTE,
            'quantity_delta' => 1,
            'inventory_sale_line_id' => null,
            'note' => 'Ajuste propio',
            'created_at' => now(),
        ]);
        InventoryMovement::query()->create([
            'inventory_lot_id' => $lotOther->id,
            'user_id' => $other->id,
            'tenant_company_id' => null,
            'type' => InventoryMovement::TYPE_AJUSTE,
            'quantity_delta' => 1,
            'inventory_sale_line_id' => null,
            'note' => 'Ajuste ajeno',
            'created_at' => now(),
        ]);

        Sanctum::actingAs($owner);

        $res = $this->getJson('/api/inventory/movements?tenant_scope=internal&per_page=50')->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame($lotOwner->id, (int) $res->json('data.0.inventory_lot_id'));

        $this->getJson('/api/inventory/movements?inventory_lot_id='.$lotOther->id.'&tenant_scope=internal')
            ->assertForbidden();
    }

    public function test_tenant_scoped_inventory_attachments_list_and_upload(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $companyA = Company::query()->create([
            'nombre' => 'Empresa Adj A',
            'factura_sigla' => 'ADJ',
            'estado' => Company::ESTADO_ACTIVO,
            'es_cliente_puntual' => false,
        ]);
        $companyB = Company::query()->create([
            'nombre' => 'Empresa Adj B',
            'factura_sigla' => 'ADK',
            'estado' => Company::ESTADO_ACTIVO,
            'es_cliente_puntual' => false,
        ]);
        $lotB = InventoryLot::query()->create([
            'owner_user_id' => $admin->id,
            'tenant_company_id' => $companyB->id,
            'name' => 'Activo adjunto',
            'sku' => 'ADK-A000001',
            'description' => null,
            'quantity_available' => 1,
            'unit_price' => '0.00',
            'is_active' => true,
            'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/inventory/lots/'.$lotB->id.'/attachments?tenant_company_id='.$companyA->id)
            ->assertForbidden();

        $file = UploadedFile::fake()->create('informe.pdf', 200, 'application/pdf');
        $this->post('/api/inventory/lots/'.$lotB->id.'/attachments?tenant_company_id='.$companyB->id, [
            'file' => $file,
        ])->assertCreated();

        $idx = $this->getJson('/api/inventory/lots/'.$lotB->id.'/attachments?tenant_company_id='.$companyB->id)->assertOk();
        $this->assertCount(1, $idx->json('data'));
        $this->assertSame('informe.pdf', $idx->json('data.0.original_filename'));
    }

    public function test_empleado_can_upload_attachment_on_owned_active_lot(): void
    {
        Storage::fake('public');
        $emp = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        $lot = InventoryLot::query()->create([
            'owner_user_id' => $emp->id,
            'tenant_company_id' => null,
            'name' => 'Equipo con adjunto',
            'sku' => 'ADJ-E1',
            'description' => null,
            'quantity_available' => 1,
            'unit_price' => '10.00',
            'is_active' => true,
            'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
        ]);

        Sanctum::actingAs($emp);
        $file = UploadedFile::fake()->image('foto.jpg');
        $this->post('/api/inventory/lots/'.$lot->id.'/attachments?tenant_scope=internal', [
            'file' => $file,
        ])->assertCreated();

        $this->assertSame(1, InventoryLotAttachment::query()->where('inventory_lot_id', $lot->id)->count());
    }

    public function test_admin_attachment_rejects_audit_event_from_other_lot(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $company = Company::query()->create([
            'nombre' => 'Empresa Audit Adj',
            'factura_sigla' => 'AUD',
            'estado' => Company::ESTADO_ACTIVO,
            'es_cliente_puntual' => false,
        ]);
        $lotA = InventoryLot::query()->create([
            'owner_user_id' => $admin->id,
            'tenant_company_id' => $company->id,
            'name' => 'Activo A',
            'sku' => 'AUD-A000001',
            'description' => null,
            'quantity_available' => 1,
            'unit_price' => '0.00',
            'is_active' => true,
            'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
        ]);
        $lotB = InventoryLot::query()->create([
            'owner_user_id' => $admin->id,
            'tenant_company_id' => $company->id,
            'name' => 'Activo B',
            'sku' => 'AUD-A000002',
            'description' => null,
            'quantity_available' => 1,
            'unit_price' => '0.00',
            'is_active' => true,
            'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
        ]);
        $auditForB = InventoryAuditEvent::query()->create([
            'tenant_company_id' => $company->id,
            'entity_type' => 'inventory_lot',
            'entity_id' => $lotB->id,
            'action' => 'update',
            'actor_user_id' => $admin->id,
            'before' => null,
            'after' => ['x' => 1],
            'occurred_at' => now(),
        ]);

        Sanctum::actingAs($admin);
        $file = UploadedFile::fake()->create('x.pdf', 100, 'application/pdf');
        $this->post('/api/inventory/lots/'.$lotA->id.'/attachments?tenant_company_id='.$company->id, [
            'file' => $file,
            'inventory_audit_event_id' => $auditForB->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['inventory_audit_event_id']);
    }

    public function test_admin_attachment_stores_inventory_audit_event_id_when_valid(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $company = Company::query()->create([
            'nombre' => 'Empresa Audit Adj OK',
            'factura_sigla' => 'AOK',
            'estado' => Company::ESTADO_ACTIVO,
            'es_cliente_puntual' => false,
        ]);
        $lot = InventoryLot::query()->create([
            'owner_user_id' => $admin->id,
            'tenant_company_id' => $company->id,
            'name' => 'Activo vinc',
            'sku' => 'AOK-A000001',
            'description' => null,
            'quantity_available' => 1,
            'unit_price' => '0.00',
            'is_active' => true,
            'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
        ]);
        $audit = InventoryAuditEvent::query()->create([
            'tenant_company_id' => $company->id,
            'entity_type' => 'inventory_lot',
            'entity_id' => $lot->id,
            'action' => 'lifecycle_report',
            'actor_user_id' => $admin->id,
            'before' => null,
            'after' => ['reason' => 'test'],
            'occurred_at' => now(),
        ]);

        Sanctum::actingAs($admin);
        $file = UploadedFile::fake()->create('evidencia.pdf', 120, 'application/pdf');
        $this->post('/api/inventory/lots/'.$lot->id.'/attachments?tenant_company_id='.$company->id, [
            'file' => $file,
            'inventory_audit_event_id' => $audit->id,
        ])->assertCreated();

        $this->assertDatabaseHas('inventory_lot_attachments', [
            'inventory_lot_id' => $lot->id,
            'inventory_audit_event_id' => $audit->id,
        ]);

        $idx = $this->getJson('/api/inventory/lots/'.$lot->id.'/attachments?tenant_company_id='.$company->id)->assertOk();
        $this->assertSame($audit->id, (int) $idx->json('data.0.linked_audit_event.id'));
        $this->assertSame('lifecycle_report', $idx->json('data.0.linked_audit_event.action'));
    }

    public function test_empleado_cannot_upload_attachment_on_foreign_lot(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        $other = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        $lot = InventoryLot::query()->create([
            'owner_user_id' => $owner->id,
            'tenant_company_id' => null,
            'name' => 'Equipo ajeno adj',
            'sku' => 'ADJ-E2',
            'description' => null,
            'quantity_available' => 1,
            'unit_price' => '10.00',
            'is_active' => true,
            'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
        ]);

        Sanctum::actingAs($other);
        $file = UploadedFile::fake()->image('x.jpg');
        $this->post('/api/inventory/lots/'.$lot->id.'/attachments?tenant_scope=internal', [
            'file' => $file,
        ])->assertForbidden();
    }

    public function test_admin_can_download_lifecycle_sheet_pdf_scoped_by_tenant(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $companyA = Company::query()->create([
            'nombre' => 'Empresa PDF A',
            'factura_sigla' => 'PFA',
            'estado' => Company::ESTADO_ACTIVO,
            'es_cliente_puntual' => false,
        ]);
        $companyB = Company::query()->create([
            'nombre' => 'Empresa PDF B',
            'factura_sigla' => 'PFB',
            'estado' => Company::ESTADO_ACTIVO,
            'es_cliente_puntual' => false,
        ]);
        $lot = InventoryLot::query()->create([
            'owner_user_id' => $admin->id,
            'tenant_company_id' => $companyB->id,
            'name' => 'Activo PDF',
            'sku' => 'PFB-A000001',
            'description' => null,
            'quantity_available' => 1,
            'unit_price' => '99.00',
            'is_active' => true,
            'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
        ]);

        Sanctum::actingAs($admin);

        $this->get('/api/inventory/lots/'.$lot->id.'/lifecycle-sheet?tenant_company_id='.$companyA->id)
            ->assertForbidden();

        $res = $this->get('/api/inventory/lots/'.$lot->id.'/lifecycle-sheet?tenant_company_id='.$companyB->id);
        $res->assertOk();
        $this->assertStringContainsString('pdf', strtolower((string) $res->headers->get('Content-Type')));
    }

    public function test_empleado_can_download_lifecycle_sheet_pdf_for_own_active_lot(): void
    {
        $emp = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        $lot = InventoryLot::query()->create([
            'owner_user_id' => $emp->id,
            'tenant_company_id' => null,
            'name' => 'Activo PDF emp',
            'sku' => 'PDF-EMP-1',
            'description' => null,
            'quantity_available' => 1,
            'unit_price' => '10.00',
            'is_active' => true,
            'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
        ]);

        Sanctum::actingAs($emp);

        $res = $this->get('/api/inventory/lots/'.$lot->id.'/lifecycle-sheet?tenant_scope=internal');
        $res->assertOk();
        $this->assertStringContainsString('pdf', strtolower((string) $res->headers->get('Content-Type')));
    }

    public function test_empleado_lists_custody_lots_for_maintenance_with_tenant_scope(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $emp = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        $company = Company::query()->create([
            'nombre' => 'Cliente Mantto Custodia',
            'factura_sigla' => 'CMC',
            'estado' => Company::ESTADO_ACTIVO,
            'es_cliente_puntual' => false,
        ]);
        $lot = InventoryLot::query()->create([
            'owner_user_id' => $admin->id,
            'tenant_company_id' => $company->id,
            'name' => 'Router custodia mantto',
            'sku' => 'CMC-A000001',
            'description' => null,
            'quantity_available' => 1,
            'unit_price' => '0.00',
            'is_active' => true,
            'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
        ]);

        Sanctum::actingAs($emp);

        $r = $this->getJson(
            '/api/inventory/lots?tenant_company_id='.$company->id.'&for_maintenance=1&per_page=50'
        )->assertOk();
        $names = collect($r->json('data'))->pluck('name')->all();
        $this->assertContains($lot->name, $names);
    }

    public function test_empleado_cannot_list_foreign_tenant_lots_without_for_maintenance_flag(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $emp = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        $company = Company::query()->create([
            'nombre' => 'Cliente Sin Flag',
            'factura_sigla' => 'CSF',
            'estado' => Company::ESTADO_ACTIVO,
            'es_cliente_puntual' => false,
        ]);
        InventoryLot::query()->create([
            'owner_user_id' => $admin->id,
            'tenant_company_id' => $company->id,
            'name' => 'Equipo ajeno',
            'sku' => 'CSF-A000001',
            'description' => null,
            'quantity_available' => 1,
            'unit_price' => '0.00',
            'is_active' => true,
            'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
        ]);

        Sanctum::actingAs($emp);

        $r = $this->getJson('/api/inventory/lots?tenant_company_id='.$company->id.'&per_page=50')->assertOk();
        $this->assertCount(0, $r->json('data'));
    }

    public function test_admin_can_filter_lots_by_created_at_range(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $old = InventoryLot::query()->create([
            'owner_user_id' => $admin->id,
            'tenant_company_id' => null,
            'name' => 'Lote viejo',
            'sku' => 'OLD-1',
            'description' => null,
            'quantity_available' => 1,
            'unit_price' => '1.00',
            'is_active' => true,
        ]);
        $old->forceFill(['created_at' => now()->subDays(30)])->saveQuietly();

        Sanctum::actingAs($admin);

        $from = now()->subDays(7)->format('Y-m-d');
        $r = $this->getJson('/api/inventory/lots?tenant_scope=internal&created_from='.$from.'&per_page=50')->assertOk();
        $ids = collect($r->json('data'))->pluck('id')->all();
        $this->assertNotContains($old->id, $ids);
    }

    public function test_empleado_movements_index_for_tenant_with_for_maintenance(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $emp = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        $company = Company::query()->create([
            'nombre' => 'Empresa Mov Mantto',
            'factura_sigla' => 'EMM',
            'estado' => Company::ESTADO_ACTIVO,
            'es_cliente_puntual' => false,
        ]);
        $lot = InventoryLot::query()->create([
            'owner_user_id' => $admin->id,
            'tenant_company_id' => $company->id,
            'name' => 'Switch',
            'sku' => 'EMM-A000001',
            'description' => null,
            'quantity_available' => 1,
            'unit_price' => '0.00',
            'is_active' => true,
            'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
        ]);
        InventoryMovement::query()->create([
            'inventory_lot_id' => $lot->id,
            'user_id' => $admin->id,
            'tenant_company_id' => $company->id,
            'type' => InventoryMovement::TYPE_AJUSTE,
            'quantity_delta' => 0,
            'inventory_sale_line_id' => null,
            'note' => 'Nota custodia',
            'created_at' => now(),
        ]);

        Sanctum::actingAs($emp);

        $r = $this->getJson(
            '/api/inventory/movements?tenant_company_id='.$company->id.'&for_maintenance=1&per_page=50'
        )->assertOk();
        $this->assertGreaterThanOrEqual(1, count($r->json('data')));
    }

    public function test_admin_inventory_csv_import_dry_run_does_not_create_lots(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $company = Company::query()->create([
            'nombre' => 'Empresa CSV dry',
            'factura_sigla' => 'DRY',
            'estado' => Company::ESTADO_ACTIVO,
            'es_cliente_puntual' => false,
        ]);

        Sanctum::actingAs($admin);

        $csv = "name;quantity;brand;site_label\nActivo DryRun;2;Dell;Sede central\n";
        $file = UploadedFile::fake()->createWithContent('import.csv', $csv);

        $res = $this->post('/api/inventory/lots/import', [
            'tenant_company_id' => $company->id,
            'dry_run' => '1',
            'file' => $file,
        ])->assertOk();

        $res->assertJsonPath('dry_run', true);
        $res->assertJsonPath('created_ids', []);
        $this->assertCount(1, $res->json('preview'));
        $this->assertSame(0, InventoryLot::query()->where('tenant_company_id', $company->id)->count());
    }

    public function test_admin_inventory_csv_import_creates_lot_movement_and_audit(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $company = Company::query()->create([
            'nombre' => 'Empresa CSV apply',
            'factura_sigla' => 'APL',
            'estado' => Company::ESTADO_ACTIVO,
            'es_cliente_puntual' => false,
        ]);

        Sanctum::actingAs($admin);

        $csv = "name;quantity;brand;serial_number\nRouter Import;1;Cisco;SN-CSV-001\n";
        $file = UploadedFile::fake()->createWithContent('import.csv', $csv);

        $res = $this->post('/api/inventory/lots/import', [
            'tenant_company_id' => $company->id,
            'dry_run' => '0',
            'file' => $file,
        ])->assertOk();

        $ids = $res->json('created_ids');
        $this->assertCount(1, $ids);
        $lotId = (int) $ids[0];

        $this->assertDatabaseHas('inventory_lots', [
            'id' => $lotId,
            'tenant_company_id' => $company->id,
            'name' => 'Router Import',
            'brand' => 'Cisco',
            'serial_number' => 'SN-CSV-001',
        ]);
        $this->assertSame(1, InventoryMovement::query()->where('inventory_lot_id', $lotId)->where('type', InventoryMovement::TYPE_ALTA)->count());
        $this->assertDatabaseHas('inventory_audit_events', [
            'entity_type' => 'inventory_lot',
            'entity_id' => $lotId,
            'action' => 'create',
        ]);
    }

    public function test_empleado_cannot_post_inventory_csv_import(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $emp = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        $company = Company::query()->create([
            'nombre' => 'Empresa CSV 403',
            'factura_sigla' => 'F99',
            'estado' => Company::ESTADO_ACTIVO,
            'es_cliente_puntual' => false,
        ]);

        Sanctum::actingAs($emp);
        $csv = "name;quantity\nX;1\n";
        $file = UploadedFile::fake()->createWithContent('import.csv', $csv);

        $this->post('/api/inventory/lots/import', [
            'tenant_company_id' => $company->id,
            'dry_run' => '1',
            'file' => $file,
        ])->assertForbidden();
    }

    public function test_admin_inventory_csv_import_rejects_empty_file(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $company = Company::query()->create([
            'nombre' => 'Empresa CSV vacío',
            'factura_sigla' => 'VAC',
            'estado' => Company::ESTADO_ACTIVO,
            'es_cliente_puntual' => false,
        ]);

        Sanctum::actingAs($admin);

        $file = UploadedFile::fake()->createWithContent('empty.csv', '');

        $this->post('/api/inventory/lots/import', [
            'tenant_company_id' => $company->id,
            'dry_run' => '1',
            'file' => $file,
        ])->assertUnprocessable()->assertJsonValidationErrors(['file']);
    }

    public function test_admin_inventory_csv_import_rejects_header_only(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $company = Company::query()->create([
            'nombre' => 'Empresa CSV solo cabecera',
            'factura_sigla' => 'HDR',
            'estado' => Company::ESTADO_ACTIVO,
            'es_cliente_puntual' => false,
        ]);

        Sanctum::actingAs($admin);

        $file = UploadedFile::fake()->createWithContent('only-header.csv', "name;quantity\n");

        $this->post('/api/inventory/lots/import', [
            'tenant_company_id' => $company->id,
            'dry_run' => '1',
            'file' => $file,
        ])->assertUnprocessable()->assertJsonValidationErrors(['file']);
    }

    public function test_admin_inventory_csv_import_duplicate_serial_second_row_errors(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $company = Company::query()->create([
            'nombre' => 'Empresa CSV dup serial',
            'factura_sigla' => 'DUP',
            'estado' => Company::ESTADO_ACTIVO,
            'es_cliente_puntual' => false,
        ]);

        Sanctum::actingAs($admin);

        $csv = "name;quantity;serial_number\nPrimero;1;SN-DUP-ROW\nSegundo;1;SN-DUP-ROW\n";
        $file = UploadedFile::fake()->createWithContent('dup-serial.csv', $csv);

        $res = $this->post('/api/inventory/lots/import', [
            'tenant_company_id' => $company->id,
            'dry_run' => '0',
            'file' => $file,
        ])->assertOk();

        $this->assertCount(1, $res->json('created_ids'));
        $this->assertCount(1, $res->json('errors'));
        $this->assertStringContainsString('Serial/MAC', $res->json('errors.0.message'));
        $this->assertSame(3, $res->json('errors.0.line'));
        $this->assertSame(1, InventoryLot::query()->where('tenant_company_id', $company->id)->count());
    }

    public function test_admin_inventory_csv_import_duplicate_mac_second_row_errors(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $company = Company::query()->create([
            'nombre' => 'Empresa CSV dup mac',
            'factura_sigla' => 'MAC',
            'estado' => Company::ESTADO_ACTIVO,
            'es_cliente_puntual' => false,
        ]);

        Sanctum::actingAs($admin);

        $csv = "name;quantity;mac_address\nEquipo A;1;AA:BB:CC:DD:EE:FA\nEquipo B;1;AA-BB-CC-DD-EE-FA\n";
        $file = UploadedFile::fake()->createWithContent('dup-mac.csv', $csv);

        $res = $this->post('/api/inventory/lots/import', [
            'tenant_company_id' => $company->id,
            'dry_run' => '0',
            'file' => $file,
        ])->assertOk();

        $this->assertCount(1, $res->json('created_ids'));
        $this->assertCount(1, $res->json('errors'));
        $this->assertSame(3, $res->json('errors.0.line'));
    }

    public function test_admin_inventory_csv_import_out_of_range_quantity_row_in_errors(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $company = Company::query()->create([
            'nombre' => 'Empresa CSV qty',
            'factura_sigla' => 'QTY',
            'estado' => Company::ESTADO_ACTIVO,
            'es_cliente_puntual' => false,
        ]);

        Sanctum::actingAs($admin);

        // quantity 0 se sustituye por 1 en el importador; usar valor fuera de rango.
        $csv = "name;quantity\nMal;1000000\n";
        $file = UploadedFile::fake()->createWithContent('bad-qty.csv', $csv);

        $res = $this->post('/api/inventory/lots/import', [
            'tenant_company_id' => $company->id,
            'dry_run' => '0',
            'file' => $file,
        ])->assertOk();

        $this->assertSame([], $res->json('created_ids'));
        $this->assertCount(1, $res->json('errors'));
        $this->assertStringContainsString('quantity', $res->json('errors.0.message'));
        $this->assertSame(2, $res->json('errors.0.line'));
    }

    public function test_lot_show_includes_warranty_semaphore_for_future_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-08 12:00:00', config('app.timezone')));
        try {
            $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
            $company = Company::query()->create([
                'nombre' => 'Empresa Garantía',
                'factura_sigla' => 'GAR',
                'estado' => Company::ESTADO_ACTIVO,
                'es_cliente_puntual' => false,
            ]);
            $lot = InventoryLot::query()->create([
                'owner_user_id' => $admin->id,
                'tenant_company_id' => $company->id,
                'name' => 'Servidor GAR',
                'sku' => 'GAR-A000001',
                'description' => null,
                'quantity_available' => 1,
                'unit_price' => '0.00',
                'is_active' => true,
                'allow_sale' => false,
                'allow_rental' => false,
                'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
                'warranty_until' => '2026-12-31',
            ]);

            Sanctum::actingAs($admin);

            $r = $this->getJson('/api/inventory/lots/'.$lot->id.'?tenant_company_id='.$company->id)->assertOk();
            $this->assertSame('2026-12-31', $r->json('data.warranty_until'));
            $this->assertSame(InventoryLotWarrantyLabels::SEMAPHORE_VIGENTE, $r->json('data.warranty_semaphore'));
            $this->assertSame('Garantía vigente', $r->json('data.warranty_semaphore_label'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_lifecycle_report_to_repair_requires_physical_condition_and_damage_kind(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $lot = InventoryLot::query()->create([
            'owner_user_id' => $admin->id,
            'tenant_company_id' => null,
            'name' => 'Equipo reparación',
            'sku' => 'REP-1',
            'description' => null,
            'quantity_available' => 1,
            'unit_price' => '0.00',
            'is_active' => true,
            'allow_sale' => true,
            'allow_rental' => false,
            'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
            'physical_condition' => 'Bueno',
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/inventory/lots/'.$lot->id.'/lifecycle/report', [
            'target_status' => 'reparacion',
            'reason' => 'Falla en disco',
        ])->assertUnprocessable();

        $r = $this->postJson('/api/inventory/lots/'.$lot->id.'/lifecycle/report', [
            'target_status' => 'reparacion',
            'reason' => 'Falla en disco',
            'physical_condition' => 'Regular',
            'repair_damage_kind' => 'hardware',
        ])->assertOk();

        $this->assertSame('Regular', $r->json('lot.physical_condition'));
        $this->assertSame('hardware', $r->json('lot.repair_damage_kind'));
        $this->assertSame(InventoryLot::LIFECYCLE_REPARACION, $r->json('lot.lifecycle_status'));

        $this->assertDatabaseHas('inventory_lots', [
            'id' => $lot->id,
            'physical_condition' => 'Regular',
            'repair_damage_kind' => 'hardware',
            'lifecycle_status' => InventoryLot::LIFECYCLE_REPARACION,
        ]);

        $audit = InventoryAuditEvent::query()
            ->where('entity_type', 'inventory_lot')
            ->where('entity_id', $lot->id)
            ->where('action', 'lifecycle_to_reparacion')
            ->latest('id')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame('Regular', $audit->after['physical_condition'] ?? null);
        $this->assertSame('hardware', $audit->after['repair_damage_kind'] ?? null);
    }

    public function test_lifecycle_report_reactivation_requires_physical_condition_and_damage_kind(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $lot = InventoryLot::query()->create([
            'owner_user_id' => $admin->id,
            'tenant_company_id' => null,
            'name' => 'Equipo reactivar',
            'sku' => 'REA-1',
            'description' => null,
            'quantity_available' => 1,
            'unit_price' => '0.00',
            'is_active' => false,
            'allow_sale' => false,
            'allow_rental' => false,
            'lifecycle_status' => InventoryLot::LIFECYCLE_REPARACION,
            'repair_reason' => 'Enviado a taller',
            'physical_condition' => 'Regular',
            'repair_damage_kind' => 'hardware',
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/inventory/lots/'.$lot->id.'/lifecycle/report', [
            'target_status' => 'activo',
            'reason' => 'Reactivación tras reparación.',
            'resolution_note' => 'Se reemplazó SSD.',
        ])->assertUnprocessable();

        $r = $this->postJson('/api/inventory/lots/'.$lot->id.'/lifecycle/report', [
            'target_status' => 'activo',
            'reason' => 'Reactivación tras reparación.',
            'resolution_note' => 'Se reemplazó SSD.',
            'physical_condition' => 'Bueno',
            'repair_damage_kind' => 'software',
        ])->assertOk();

        $this->assertSame('Bueno', $r->json('lot.physical_condition'));
        $this->assertSame('software', $r->json('lot.repair_damage_kind'));
        $this->assertSame(InventoryLot::LIFECYCLE_ACTIVO, $r->json('lot.lifecycle_status'));
    }
}
