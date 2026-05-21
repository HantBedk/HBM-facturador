<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PanelNotification;
use App\Models\Service;
use App\Models\ServiceCatalog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServiceAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-15 14:30:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function seedCompanyAndCatalog(): array
    {
        $company = Company::query()->create([
            'nombre' => 'EmpAsg',
            'factura_sigla' => 'EAS',
            'nit' => '901-1',
            'estado' => Company::ESTADO_ACTIVO,
        ]);

        $cat = ServiceCatalog::query()->create([
            'name' => 'Prueba asignación',
            'description' => 'Descripción larga para validación de catálogo.',
            'base_price' => 50000,
            'status' => ServiceCatalog::STATUS_ACTIVO,
        ]);

        return [$company, $cat];
    }

    public function test_admin_assigns_service_and_technician_completes(): void
    {
        [$company, $cat] = $this->seedCompanyAndCatalog();

        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $tech = User::factory()->create(['rol' => User::ROL_EMPLEADO, 'estado' => User::ESTADO_ACTIVO]);

        Sanctum::actingAs($admin);

        $r = $this->postJson('/api/admin/services/assign-to-technician', [
            'technician_user_id' => $tech->id,
            'company_id' => $company->id,
            'catalog_id' => $cat->id,
        ]);

        $r->assertCreated();
        $sid = (int) $r->json('data.id');
        $this->assertSame(Service::ASSIGNMENT_AWAITING_COMPLETION, $r->json('data.assignment_status'));

        $this->assertDatabaseHas('panel_notifications', [
            'user_id' => $tech->id,
            'type' => PanelNotification::TYPE_EMP_SERVICIO_ASIGNADO_ADMIN,
        ]);

        Sanctum::actingAs($tech);

        $complete = $this->postJson('/api/services/'.$sid.'/complete-assignment', [
            'client_name' => 'Cliente obra',
            'service_type' => 'Prueba asignación',
            'items' => [
                [
                    'catalog_id' => $cat->id,
                    'amount' => 100,
                    'line_description' => 'Trabajo realizado en sitio según lo acordado con el cliente en obra.',
                ],
            ],
        ]);

        $complete->assertOk();
        $this->assertNull($complete->json('data.assignment_status'));
    }

    public function test_technician_can_reject_assignment(): void
    {
        [$company, $cat] = $this->seedCompanyAndCatalog();

        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $tech = User::factory()->create(['rol' => User::ROL_EMPLEADO, 'estado' => User::ESTADO_ACTIVO]);

        Sanctum::actingAs($admin);
        $r = $this->postJson('/api/admin/services/assign-to-technician', [
            'technician_user_id' => $tech->id,
            'company_id' => $company->id,
            'catalog_id' => $cat->id,
        ]);
        $r->assertCreated();
        $sid = (int) $r->json('data.id');

        Sanctum::actingAs($tech);
        $rej = $this->postJson('/api/services/'.$sid.'/reject-assignment');
        $rej->assertOk();
        $this->assertSame(Service::STATUS_ELIMINADO, $rej->json('data.status'));
        $this->assertSame(Service::ASSIGNMENT_REJECTED, $rej->json('data.assignment_status'));

        $this->assertDatabaseHas('panel_notifications', [
            'type' => PanelNotification::TYPE_ADMIN_ASIGNACION_RECHAZADA,
        ]);
    }

    public function test_admin_assigns_service_without_catalog(): void
    {
        [$company] = $this->seedCompanyAndCatalog();

        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $tech = User::factory()->create(['rol' => User::ROL_EMPLEADO, 'estado' => User::ESTADO_ACTIVO]);

        Sanctum::actingAs($admin);

        $r = $this->postJson('/api/admin/services/assign-to-technician', [
            'technician_user_id' => $tech->id,
            'company_id' => $company->id,
        ]);

        $r->assertCreated();
        $this->assertNull($r->json('data.catalog_id'));
        $this->assertSame(Service::ASSIGNMENT_AWAITING_COMPLETION, $r->json('data.assignment_status'));
        $this->assertSame('Servicio asignado', $r->json('data.service_type'));
    }

    public function test_complete_assignment_forbidden_for_admin(): void
    {
        [$company, $cat] = $this->seedCompanyAndCatalog();
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $tech = User::factory()->create(['rol' => User::ROL_EMPLEADO, 'estado' => User::ESTADO_ACTIVO]);

        Sanctum::actingAs($admin);
        $r = $this->postJson('/api/admin/services/assign-to-technician', [
            'technician_user_id' => $tech->id,
            'company_id' => $company->id,
            'catalog_id' => $cat->id,
        ]);
        $sid = (int) $r->json('data.id');

        Sanctum::actingAs($admin);
        $this->postJson('/api/services/'.$sid.'/complete-assignment', [
            'client_name' => 'X',
            'service_type' => 'Y',
            'items' => [
                [
                    'catalog_id' => $cat->id,
                    'amount' => 100,
                    'line_description' => 'Trabajo realizado en sitio según lo acordado con el cliente en obra.',
                ],
            ],
        ])->assertStatus(403);
    }
}
