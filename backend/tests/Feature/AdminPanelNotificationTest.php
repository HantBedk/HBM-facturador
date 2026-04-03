<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\PanelNotification;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPanelNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_notifications_and_unread_count(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        PanelNotification::query()->create([
            'user_id' => $admin->id,
            'type' => PanelNotification::TYPE_INVOICE_DRAFT,
            'message' => 'Prueba',
            'read' => false,
            'meta' => ['link' => '/admin/facturas'],
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('by_category.facturas', 1)
            ->assertJsonPath('by_category.empleados', 0)
            ->assertJsonPath('by_category.servicios', 0);

        $this->getJson('/api/admin/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.category', 'facturas');
    }

    public function test_admin_notifications_filter_by_category(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        PanelNotification::query()->create([
            'user_id' => $admin->id,
            'type' => PanelNotification::TYPE_INVOICE_DRAFT,
            'message' => 'Factura',
            'read' => false,
            'meta' => null,
        ]);
        PanelNotification::query()->create([
            'user_id' => $admin->id,
            'type' => PanelNotification::TYPE_SERVICE_CREATED,
            'message' => 'Servicio',
            'read' => false,
            'meta' => null,
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/notifications?category=facturas')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', PanelNotification::TYPE_INVOICE_DRAFT);

        $this->getJson('/api/admin/notifications?category=servicios')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', PanelNotification::TYPE_SERVICE_CREATED);
    }

    public function test_empleado_first_profile_completion_notifies_admins(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $emp = User::factory()->create([
            'rol' => User::ROL_EMPLEADO,
            'perfil_completado_at' => null,
        ]);

        Sanctum::actingAs($emp);

        $payload = [
            'nombre' => 'Técnico Test',
            'telefono' => '3001234567',
            'tipo_documento' => 'CC',
            'numero_documento' => '12345678',
            'ciudad' => 'Bogotá',
            'departamento' => 'Cundinamarca',
            'banco_codigo' => 'BANCOLOMBIA',
            'cuenta_tipo' => 'ahorros',
            'cuenta_numero' => '12345678901',
        ];

        $this->putJson('/api/empleado/perfil', $payload)->assertOk();

        $this->assertSame(1, PanelNotification::query()
            ->where('user_id', $admin->id)
            ->where('type', PanelNotification::TYPE_EMPLEADO_PERFIL_COMPLETADO)
            ->count());

        $this->putJson('/api/empleado/perfil', array_merge($payload, ['nombre' => 'Otro nombre']))->assertOk();

        $this->assertSame(1, PanelNotification::query()
            ->where('user_id', $admin->id)
            ->where('type', PanelNotification::TYPE_EMPLEADO_PERFIL_COMPLETADO)
            ->count());
    }

    public function test_admin_can_mark_notification_read(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $n = PanelNotification::query()->create([
            'user_id' => $admin->id,
            'type' => PanelNotification::TYPE_INVOICE_DRAFT,
            'message' => 'Prueba',
            'read' => false,
        ]);

        Sanctum::actingAs($admin);

        $this->patchJson('/api/admin/notifications/'.$n->id.'/read')
            ->assertOk()
            ->assertJsonPath('data.read', true);

        $this->getJson('/api/admin/notifications/unread-count')
            ->assertJsonPath('count', 0);
    }

    public function test_invoice_create_notifies_admins(): void
    {
        $company = Company::query()->create([
            'nombre' => 'Co',
            'nit' => '9001-1',
            'estado' => Company::ESTADO_ACTIVO,
        ]);
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $empleado = User::factory()->create(['rol' => User::ROL_EMPLEADO]);

        $service = Service::query()->create([
            'code' => 'S-1',
            'company_id' => $company->id,
            'user_id' => $empleado->id,
            'client_name' => 'C',
            'service_type' => 'T',
            'description' => 'Descripción larga del servicio.',
            'amount' => 10000,
            'service_date' => '2026-04-10',
            'status' => Service::STATUS_ACTIVO,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/invoices', [
            'company_id' => $company->id,
            'period_year' => 2026,
            'period_month' => 4,
            'service_ids' => [$service->id],
        ])->assertCreated();

        $this->assertGreaterThan(0, PanelNotification::query()->where('user_id', $admin->id)->where('type', PanelNotification::TYPE_INVOICE_DRAFT)->count());
    }

    public function test_empleado_cannot_access_notifications_panel(): void
    {
        $emp = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        Sanctum::actingAs($emp);

        $this->getJson('/api/admin/notifications/unread-count')->assertForbidden();
    }

    public function test_cutoff_command_marks_aprobada_as_enviada_on_cutoff_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-28 08:00:00', config('app.timezone')));
        config([
            'automation.cutoff_enabled' => true,
            'automation.cutoff_day' => 28,
        ]);

        $company = Company::query()->create([
            'nombre' => 'Co Cut',
            'nit' => '9002-2',
            'estado' => Company::ESTADO_ACTIVO,
        ]);
        $empleado = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        $service = Service::query()->create([
            'code' => 'S-CUT',
            'company_id' => $company->id,
            'user_id' => $empleado->id,
            'client_name' => 'C',
            'service_type' => 'T',
            'description' => 'Descripción larga del servicio de corte.',
            'amount' => 50000,
            'service_date' => '2026-03-15',
            'status' => Service::STATUS_ACTIVO,
        ]);

        $invoice = Invoice::query()->create([
            'code' => 'T-CUT-1',
            'company_id' => $company->id,
            'period_month' => 3,
            'period_year' => 2026,
            'status' => Invoice::STATUS_APROBADA,
            'subtotal' => 50000,
            'total' => 50000,
            'sent_at' => null,
        ]);
        $invoice->services()->sync([$service->id]);

        Artisan::call('billing:process-cutoff');

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_ENVIADA, $invoice->status);
        $this->assertNotNull($invoice->sent_at);

        Carbon::setTestNow();
    }
}
