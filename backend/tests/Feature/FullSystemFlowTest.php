<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Flujo de negocio completo (API): login, servicio, factura, estados, pago, PDF, consulta pública.
 */
class FullSystemFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_flujo_completo_admin_empleado_y_consulta_publica(): void
    {
        $company = Company::query()->create([
            'nombre' => 'Empresa Flujo',
            'factura_sigla' => 'EFL',
            'nit' => '900555666-1',
            'estado' => Company::ESTADO_ACTIVO,
        ]);

        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $empleado = User::factory()->create(['rol' => User::ROL_EMPLEADO]);

        Sanctum::actingAs($empleado);
        $svc = $this->postJson('/api/services', [
            'company_id' => $company->id,
            'client_name' => 'Cliente',
            'service_type' => 'Tipo',
            'description' => 'Descripción del servicio de prueba con texto suficiente.',
            'amount' => 250000.5,
            'service_date' => '2026-04-10',
        ]);
        $svc->assertCreated();
        $serviceId = $svc->json('data.id');

        Sanctum::actingAs($admin);
        $inv = $this->postJson('/api/admin/invoices', [
            'company_id' => $company->id,
            'period_year' => 2026,
            'period_month' => 4,
            'service_ids' => [$serviceId],
        ]);
        $inv->assertCreated();
        $invoiceId = $inv->json('data.id');
        $this->assertSame('250000.50', $inv->json('data.total'));

        $this->patchJson('/api/admin/invoices/'.$invoiceId.'/status', [
            'status' => Invoice::STATUS_APROBADA,
        ])->assertOk()->assertJsonPath('data.status', Invoice::STATUS_APROBADA);

        $plain = $this->patchJson('/api/admin/invoices/'.$invoiceId.'/status', [
            'status' => Invoice::STATUS_ENVIADA,
        ])->assertOk()->json('data.status');
        $this->assertSame(Invoice::STATUS_ENVIADA, $plain);

        $this->postJson('/api/admin/invoices/'.$invoiceId.'/payments', [
            'amount' => 250000.5,
            'payment_date' => '2026-04-15',
            'method' => 'Transferencia',
        ])->assertOk()
            ->assertJsonPath('data.status', Invoice::STATUS_PAGADA)
            ->assertJsonPath('data.financial.balance', '0.00');

        Sanctum::actingAs($admin);
        $pdf = $this->get('/api/admin/invoices/'.$invoiceId.'/pdf');
        $pdf->assertOk();
        $this->assertStringContainsString('pdf', (string) $pdf->headers->get('Content-Type'));

        $invoice = Invoice::query()->findOrFail($invoiceId);

        $this->postJson('/api/public/invoices/consult', [
            'query' => $invoice->code,
        ])->assertOk()
            ->assertJsonPath('kind', 'invoice')
            ->assertJsonPath('invoice.code', $invoice->code);
    }

    public function test_empleado_no_puede_ver_servicio_de_otro_empleado(): void
    {
        $company = Company::query()->create([
            'nombre' => 'Co',
            'factura_sigla' => 'COA',
            'nit' => '900111222-3',
            'estado' => Company::ESTADO_ACTIVO,
        ]);
        $e1 = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        $e2 = User::factory()->create(['rol' => User::ROL_EMPLEADO]);

        $s = Service::query()->create([
            'code' => 'S-OTRO',
            'company_id' => $company->id,
            'user_id' => $e1->id,
            'client_name' => 'X',
            'service_type' => 'T',
            'description' => 'Descripción larga del servicio ajeno.',
            'amount' => 100,
            'service_date' => '2026-04-01',
            'status' => Service::STATUS_ACTIVO,
        ]);

        Sanctum::actingAs($e2);
        $this->getJson('/api/services/'.$s->id)->assertForbidden();
    }

    public function test_empleado_no_accede_a_rutas_admin(): void
    {
        $empleado = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        Sanctum::actingAs($empleado);
        $this->getJson('/api/admin/invoices')->assertForbidden();
    }

    public function test_crear_servicio_sin_datos_obligatorios_falla_validacion(): void
    {
        $u = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        Sanctum::actingAs($u);
        $this->postJson('/api/services', [])->assertStatus(422);
    }

    public function test_crear_factura_sin_servicios_falla_validacion(): void
    {
        $company = Company::query()->create([
            'nombre' => 'Co2',
            'factura_sigla' => 'COD',
            'nit' => '900111222-4',
            'estado' => Company::ESTADO_ACTIVO,
        ]);
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/invoices', [
            'company_id' => $company->id,
            'period_year' => 2026,
            'period_month' => 5,
            'service_ids' => [],
        ])->assertStatus(422);
    }

    public function test_paginacion_respeta_tope_per_page(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);
        $r = $this->getJson('/api/admin/invoices?per_page=500');
        $r->assertOk();
        $this->assertSame(100, $r->json('meta.per_page'));
    }

    public function test_health_endpoint_responde_ok(): void
    {
        $this->getJson('/api/health')->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('database', 'ok');
    }
}
