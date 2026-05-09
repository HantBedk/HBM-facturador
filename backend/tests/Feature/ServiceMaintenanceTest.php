<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\InventoryAuditEvent;
use App\Models\InventoryLot;
use App\Mail\MaintenanceCompanyNotifyMail;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServiceMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_maintenance_service_linked_to_inventory_lot(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $company = Company::query()->create([
            'nombre' => 'Empresa Mantto',
            'factura_sigla' => 'EMT',
            'nit' => '901-MANT',
            'estado' => Company::ESTADO_ACTIVO,
        ]);
        $lot = InventoryLot::query()->create([
            'owner_user_id' => $admin->id,
            'tenant_company_id' => $company->id,
            'name' => 'Laptop mantenimiento',
            'sku' => 'EMT-A000001',
            'description' => 'Activo de prueba',
            'quantity_available' => 1,
            'unit_price' => '0.00',
            'is_active' => true,
            'allow_sale' => false,
            'allow_rental' => false,
            'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
        ]);

        Sanctum::actingAs($admin);

        $res = $this->postJson('/api/services', [
            'company_id' => $company->id,
            'kind' => Service::KIND_MANTENIMIENTO,
            'inventory_lot_id' => $lot->id,
            'client_name' => 'Empresa Mantto',
            'service_type' => 'Mantenimiento preventivo',
            'description' => 'Mantenimiento preventivo semestral del equipo',
            'items' => [[
                'custom_name' => 'Mantenimiento preventivo',
                'amount' => 120000,
                'line_description' => 'Limpieza, revisión general y prueba funcional.',
            ]],
        ])->assertCreated();

        $serviceId = (int) $res->json('data.id');
        $this->assertSame(Service::KIND_MANTENIMIENTO, $res->json('data.kind'));
        $this->assertSame($lot->id, (int) $res->json('data.inventory_lot_id'));

        $this->assertDatabaseHas('services', [
            'id' => $serviceId,
            'kind' => Service::KIND_MANTENIMIENTO,
            'inventory_lot_id' => $lot->id,
            'company_id' => $company->id,
        ]);

        $audit = InventoryAuditEvent::query()
            ->where('entity_type', 'inventory_lot')
            ->where('entity_id', $lot->id)
            ->where('action', 'maintenance_service_linked')
            ->latest('id')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame($serviceId, (int) ($audit->after['service_id'] ?? 0));

        $detail = $this->getJson('/api/services/'.$serviceId)->assertOk();
        $detail->assertJsonPath('data.inventory_lot.id', $lot->id);
        $detail->assertJsonPath('data.inventory_lot.name', 'Laptop mantenimiento');
        $this->assertSame('EMT-A000001', $detail->json('data.inventory_lot.internal_code'));
    }

    public function test_maintenance_sends_email_when_company_has_correo(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $company = Company::query()->create([
            'nombre' => 'Empresa Con Correo',
            'factura_sigla' => 'ECC',
            'nit' => '901-ECC',
            'correo' => 'cliente-mantto@test.local',
            'estado' => Company::ESTADO_ACTIVO,
        ]);
        $lot = InventoryLot::query()->create([
            'owner_user_id' => $admin->id,
            'tenant_company_id' => $company->id,
            'name' => 'Servidor rack',
            'sku' => 'ECC-B000001',
            'description' => 'Activo',
            'quantity_available' => 1,
            'unit_price' => '0.00',
            'is_active' => true,
            'allow_sale' => false,
            'allow_rental' => false,
            'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/services', [
            'company_id' => $company->id,
            'kind' => Service::KIND_MANTENIMIENTO,
            'inventory_lot_id' => $lot->id,
            'client_name' => 'Empresa Con Correo',
            'service_type' => 'Mantenimiento correctivo',
            'description' => 'Reemplazo de ventilador y pruebas.',
            'items' => [[
                'custom_name' => 'Mantenimiento correctivo',
                'amount' => 80000,
                'line_description' => 'Mano de obra y repuesto.',
            ]],
        ])->assertCreated();

        Mail::assertSent(MaintenanceCompanyNotifyMail::class, function (MaintenanceCompanyNotifyMail $m) {
            return str_contains($m->subjectLine, 'Servidor rack')
                && str_contains((string) $m->htmlBody, 'Reemplazo de ventilador');
        });
    }

    public function test_maintenance_rejects_lot_from_other_company(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $companyA = Company::query()->create([
            'nombre' => 'Empresa A',
            'factura_sigla' => 'EMA',
            'nit' => '901-A',
            'estado' => Company::ESTADO_ACTIVO,
        ]);
        $companyB = Company::query()->create([
            'nombre' => 'Empresa B',
            'factura_sigla' => 'EMB',
            'nit' => '901-B',
            'estado' => Company::ESTADO_ACTIVO,
        ]);
        $lotB = InventoryLot::query()->create([
            'owner_user_id' => $admin->id,
            'tenant_company_id' => $companyB->id,
            'name' => 'Router B',
            'sku' => 'EMB-A000001',
            'description' => null,
            'quantity_available' => 1,
            'unit_price' => '0.00',
            'is_active' => true,
            'allow_sale' => false,
            'allow_rental' => false,
            'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/services', [
            'company_id' => $companyA->id,
            'kind' => Service::KIND_MANTENIMIENTO,
            'inventory_lot_id' => $lotB->id,
            'client_name' => 'Empresa A',
            'service_type' => 'Mantenimiento correctivo',
            'description' => 'Diagnóstico y reparación de equipo',
            'items' => [[
                'custom_name' => 'Mantenimiento correctivo',
                'amount' => 200000,
                'line_description' => 'Se reemplazan componentes y se calibra funcionamiento.',
            ]],
        ])->assertUnprocessable();
    }

    public function test_admin_can_filter_service_index_by_kind_mantenimiento(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $company = Company::query()->create([
            'nombre' => 'Empresa Filtro',
            'factura_sigla' => 'EMF',
            'nit' => '901-FILT',
            'estado' => Company::ESTADO_ACTIVO,
        ]);

        Service::query()->create([
            'code' => 'SERV-26050801',
            'company_id' => $company->id,
            'user_id' => $admin->id,
            'client_name' => 'Cliente A',
            'service_type' => 'Servicio general',
            'description' => 'Servicio general de prueba',
            'amount' => '100000.00',
            'service_date' => now()->toDateString(),
            'status' => Service::STATUS_ACTIVO,
            'kind' => Service::KIND_SERVICIO,
        ]);
        $lot = InventoryLot::query()->create([
            'owner_user_id' => $admin->id,
            'tenant_company_id' => $company->id,
            'name' => 'Switch filtro',
            'sku' => 'EMF-A000001',
            'description' => null,
            'quantity_available' => 1,
            'unit_price' => '0.00',
            'is_active' => true,
            'allow_sale' => false,
            'allow_rental' => false,
            'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
        ]);
        Service::query()->create([
            'code' => 'SERV-26050802',
            'company_id' => $company->id,
            'user_id' => $admin->id,
            'inventory_lot_id' => $lot->id,
            'client_name' => 'Cliente B',
            'service_type' => 'Mantenimiento',
            'description' => 'Mantenimiento preventivo',
            'amount' => '150000.00',
            'service_date' => now()->toDateString(),
            'status' => Service::STATUS_ACTIVO,
            'kind' => Service::KIND_MANTENIMIENTO,
        ]);

        Sanctum::actingAs($admin);

        $res = $this->getJson('/api/services?kind=mantenimiento')->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame(Service::KIND_MANTENIMIENTO, $res->json('data.0.kind'));
        $this->assertSame($lot->id, (int) $res->json('data.0.inventory_lot.id'));
        $this->assertSame('Switch filtro', $res->json('data.0.inventory_lot.name'));
        $this->assertSame('EMF-A000001', $res->json('data.0.inventory_lot.internal_code'));
    }

    public function test_empleado_service_index_keeps_scope_when_filtering_maintenance(): void
    {
        $company = Company::query()->create([
            'nombre' => 'Empresa Scope',
            'factura_sigla' => 'EMS',
            'nit' => '901-SCOPE',
            'estado' => Company::ESTADO_ACTIVO,
        ]);
        $empA = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        $empB = User::factory()->create(['rol' => User::ROL_EMPLEADO]);

        Service::query()->create([
            'code' => 'SERV-26050811',
            'company_id' => $company->id,
            'user_id' => $empA->id,
            'client_name' => 'Cliente Scope',
            'service_type' => 'Mantenimiento',
            'description' => 'Mantenimiento técnico A',
            'amount' => '180000.00',
            'service_date' => now()->toDateString(),
            'status' => Service::STATUS_ACTIVO,
            'kind' => Service::KIND_MANTENIMIENTO,
        ]);
        Service::query()->create([
            'code' => 'SERV-26050812',
            'company_id' => $company->id,
            'user_id' => $empB->id,
            'client_name' => 'Cliente Scope',
            'service_type' => 'Mantenimiento',
            'description' => 'Mantenimiento técnico B',
            'amount' => '190000.00',
            'service_date' => now()->toDateString(),
            'status' => Service::STATUS_ACTIVO,
            'kind' => Service::KIND_MANTENIMIENTO,
        ]);

        Sanctum::actingAs($empA);

        $res = $this->getJson('/api/services?kind=mantenimiento')->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame((int) $empA->id, (int) $res->json('data.0.user_id'));
        $this->assertSame(Service::KIND_MANTENIMIENTO, $res->json('data.0.kind'));
    }

    public function test_admin_service_index_q_matches_linked_inventory_lot_and_export_aligns(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $company = Company::query()->create([
            'nombre' => 'Empresa Q Lote',
            'factura_sigla' => 'EMQ',
            'nit' => '901-QLOT',
            'estado' => Company::ESTADO_ACTIVO,
        ]);
        $lot = InventoryLot::query()->create([
            'owner_user_id' => $admin->id,
            'tenant_company_id' => $company->id,
            'name' => 'Firewall marca ZetaSearch',
            'serial_number' => 'SN-QLOTE-ONLY',
            'sku' => 'EMQ-A000501',
            'description' => null,
            'quantity_available' => 1,
            'unit_price' => '0.00',
            'is_active' => true,
            'allow_sale' => false,
            'allow_rental' => false,
            'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
        ]);
        Service::query()->create([
            'code' => 'SERV-QLOTE-01',
            'company_id' => $company->id,
            'user_id' => $admin->id,
            'inventory_lot_id' => $lot->id,
            'client_name' => 'Cliente Q',
            'service_type' => 'Visita',
            'description' => 'Revisión rutinaria sin mencionar serial.',
            'amount' => '80000.00',
            'service_date' => now()->toDateString(),
            'status' => Service::STATUS_ACTIVO,
            'kind' => Service::KIND_MANTENIMIENTO,
        ]);

        Sanctum::actingAs($admin);

        $bySerial = $this->getJson('/api/services?q='.rawurlencode('SN-QLOTE-ONLY'))->assertOk();
        $this->assertCount(1, $bySerial->json('data'));
        $this->assertSame('SERV-QLOTE-01', $bySerial->json('data.0.code'));

        $byLotName = $this->getJson('/api/services?q='.rawurlencode('ZetaSearch'))->assertOk();
        $this->assertCount(1, $byLotName->json('data'));

        $bySku = $this->getJson('/api/services?q='.rawurlencode('EMQ-A000501'))->assertOk();
        $this->assertCount(1, $bySku->json('data'));

        $exp = $this->get('/api/admin/export/services?q='.rawurlencode('SN-QLOTE-ONLY'))->assertOk();
        $body = $exp->streamedContent();
        $lines = preg_split("/\r\n|\n|\r/", trim(substr($body, 3)));
        $this->assertCount(2, $lines);
        $row = str_getcsv($lines[1], ';');
        $this->assertSame('SERV-QLOTE-01', $row[3]);
    }

    public function test_admin_can_create_invoice_borrador_from_maintenance_service(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $company = Company::query()->create([
            'nombre' => 'Empresa Mantto Fac',
            'factura_sigla' => 'EMF',
            'nit' => '901-MFAC',
            'estado' => Company::ESTADO_ACTIVO,
        ]);
        $lot = InventoryLot::query()->create([
            'owner_user_id' => $admin->id,
            'tenant_company_id' => $company->id,
            'name' => 'Equipo MF',
            'sku' => 'EMF-A000099',
            'description' => 'Activo de prueba',
            'quantity_available' => 1,
            'unit_price' => '0.00',
            'is_active' => true,
            'allow_sale' => false,
            'allow_rental' => false,
            'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
        ]);

        Sanctum::actingAs($admin);

        $svcRes = $this->postJson('/api/services', [
            'company_id' => $company->id,
            'kind' => Service::KIND_MANTENIMIENTO,
            'inventory_lot_id' => $lot->id,
            'client_name' => 'Empresa Mantto Fac',
            'service_type' => 'Mantenimiento preventivo',
            'description' => 'Mantenimiento preventivo semestral del equipo MF',
            'items' => [[
                'custom_name' => 'Mantenimiento preventivo',
                'amount' => 195000,
                'line_description' => 'Revisión y limpieza.',
            ]],
        ])->assertCreated();

        $serviceId = (int) $svcRes->json('data.id');
        $t = now();
        $invRes = $this->postJson('/api/admin/invoices', [
            'company_id' => $company->id,
            'period_year' => (int) $t->format('Y'),
            'period_month' => (int) $t->format('n'),
            'service_ids' => [$serviceId],
        ])->assertCreated();

        $invoiceId = (int) $invRes->json('data.id');
        $this->assertNotEmpty($invRes->json('data.code'));
        $this->assertDatabaseHas('invoice_service', [
            'invoice_id' => $invoiceId,
            'service_id' => $serviceId,
        ]);
    }

    public function test_admin_export_services_csv_respects_kind_and_includes_inventory_columns(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-10 10:00:00', config('app.timezone')));
        try {
            $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
            $company = Company::query()->create([
                'nombre' => 'Empresa Export CSV',
                'factura_sigla' => 'EXC',
                'nit' => '901-EXP',
                'estado' => Company::ESTADO_ACTIVO,
            ]);
            $lot = InventoryLot::query()->create([
                'owner_user_id' => $admin->id,
                'tenant_company_id' => $company->id,
                'name' => 'Firewall export',
                'sku' => 'EXC-A000055',
                'description' => null,
                'quantity_available' => 1,
                'unit_price' => '0.00',
                'is_active' => true,
                'allow_sale' => false,
                'allow_rental' => false,
                'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
            ]);
            Service::query()->create([
                'code' => 'SERV-EXP-01',
                'company_id' => $company->id,
                'user_id' => $admin->id,
                'client_name' => 'Cliente genérico',
                'service_type' => 'Instalación',
                'description' => 'Servicio estándar',
                'amount' => '50000.00',
                'service_date' => '2026-05-05',
                'status' => Service::STATUS_ACTIVO,
                'kind' => Service::KIND_SERVICIO,
            ]);
            Service::query()->create([
                'code' => 'SERV-EXP-02',
                'company_id' => $company->id,
                'user_id' => $admin->id,
                'inventory_lot_id' => $lot->id,
                'client_name' => 'Cliente mantto',
                'service_type' => 'Mantenimiento',
                'description' => 'Mantenimiento TOKEN_EXPORT_Q',
                'amount' => '75000.00',
                'service_date' => '2026-05-06',
                'status' => Service::STATUS_ACTIVO,
                'kind' => Service::KIND_MANTENIMIENTO,
            ]);

            Sanctum::actingAs($admin);

            $resKind = $this->get('/api/admin/export/services?kind=mantenimiento');
            $resKind->assertOk();
            $bodyKind = $resKind->streamedContent();
            $this->assertStringStartsWith("\xEF\xBB\xBF", $bodyKind);
            $linesKind = preg_split("/\r\n|\n|\r/", trim(substr($bodyKind, 3)));
            $this->assertGreaterThanOrEqual(2, count($linesKind));
            $header = str_getcsv($linesKind[0], ';');
            $this->assertSame('Clase registro', $header[4]);
            $this->assertSame('ID activo inventario', $header[5]);
            $this->assertSame('Equipo (nombre)', $header[6]);
            $this->assertSame('Equipo código interno', $header[7]);
            $this->assertCount(2, $linesKind);
            $row = str_getcsv($linesKind[1], ';');
            $this->assertSame(Service::KIND_MANTENIMIENTO, $row[4]);
            $this->assertSame((string) $lot->id, $row[5]);
            $this->assertSame('Firewall export', $row[6]);
            $this->assertSame('EXC-A000055', $row[7]);

            $resQ = $this->get('/api/admin/export/services?q='.rawurlencode('TOKEN_EXPORT_Q'));
            $resQ->assertOk();
            $bodyQ = $resQ->streamedContent();
            $linesQ = preg_split("/\r\n|\n|\r/", trim(substr($bodyQ, 3)));
            $this->assertCount(2, $linesQ);
            $rowQ = str_getcsv($linesQ[1], ';');
            $this->assertStringContainsString('TOKEN_EXPORT_Q', $rowQ[9] ?? '');
        } finally {
            Carbon::setTestNow();
        }
    }
}
