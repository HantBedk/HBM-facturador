<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WalkInCounterFinalInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_emite_factura_aprobada_con_todos_los_servicios_mismo_telefono(): void
    {
        $t1 = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        $t2 = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);

        Service::query()->create([
            'code' => 'W-SVC-01',
            'company_id' => null,
            'user_id' => $t1->id,
            'client_name' => 'Comprador X',
            'client_telefono' => '300 123 4567',
            'contact_phone_key' => '3001234567',
            'service_type' => 'Teclado',
            'description' => 'Instalación de teclado con texto suficientemente largo.',
            'amount' => 80000.00,
            'service_date' => '2026-03-10',
            'status' => Service::STATUS_ACTIVO,
        ]);
        Service::query()->create([
            'code' => 'W-SVC-02',
            'company_id' => null,
            'user_id' => $t2->id,
            'client_name' => 'Comprador X',
            'client_telefono' => '3001234567',
            'contact_phone_key' => '3001234567',
            'service_type' => 'Cable',
            'description' => 'Cableado adicional con descripción suficiente.',
            'amount' => 45000.00,
            'service_date' => '2026-03-12',
            'status' => Service::STATUS_ACTIVO,
        ]);

        Sanctum::actingAs($admin);

        $r = $this->postJson('/api/admin/invoices/counter-final', [
            'contact_phone_key' => '3001234567',
            'period_year' => 2026,
            'period_month' => 3,
        ]);

        $r->assertCreated()
            ->assertJsonPath('data.status', Invoice::STATUS_APROBADA)
            ->assertJsonPath('data.total', '125000.00');

        $this->assertNotEmpty($r->json('public_verification_code'));

        $invId = (int) $r->json('data.id');
        $inv = Invoice::query()->with('services')->findOrFail($invId);
        $this->assertSame(Invoice::STATUS_APROBADA, $inv->status);
        $this->assertCount(2, $inv->services);
        $this->assertStringStartsWith('FAC-', $inv->code);
    }

    public function test_counter_final_falla_sin_servicios_pendientes(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/invoices/counter-final', [
            'contact_phone_key' => '3009998877',
            'period_year' => 2026,
            'period_month' => 3,
        ])->assertStatus(422);
    }

    public function test_pending_walk_in_groups_lista_agrupada_y_queda_vacia_tras_counter_final(): void
    {
        $t1 = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        $t2 = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);

        Service::query()->create([
            'code' => 'W-PEND-01',
            'company_id' => null,
            'user_id' => $t1->id,
            'client_name' => 'Cliente Lista',
            'client_telefono' => '300 111 2233',
            'contact_phone_key' => '3001112233',
            'service_type' => 'Teclado',
            'description' => 'Servicio uno con descripción suficiente para reglas.',
            'amount' => 50000.00,
            'service_date' => '2026-02-05',
            'status' => Service::STATUS_ACTIVO,
        ]);
        Service::query()->create([
            'code' => 'W-PEND-02',
            'company_id' => null,
            'user_id' => $t2->id,
            'client_name' => 'Cliente Lista',
            'client_telefono' => '3001112233',
            'contact_phone_key' => '3001112233',
            'service_type' => 'Cable',
            'description' => 'Servicio dos con descripción suficiente para reglas.',
            'amount' => 25000.00,
            'service_date' => '2026-02-20',
            'status' => Service::STATUS_ACTIVO,
        ]);

        Sanctum::actingAs($admin);

        $list = $this->getJson('/api/admin/invoices/pending-walk-in-groups');
        $list->assertOk();
        $data = $list->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('3001112233', $data[0]['contact_phone_key']);
        $this->assertSame(2026, $data[0]['period_year']);
        $this->assertSame(2, $data[0]['period_month']);
        $this->assertSame(2, $data[0]['services_count']);
        $this->assertSame('75000.00', $data[0]['total']);

        $this->postJson('/api/admin/invoices/counter-final', [
            'contact_phone_key' => '3001112233',
            'period_year' => 2026,
            'period_month' => 2,
        ])->assertCreated();

        $after = $this->getJson('/api/admin/invoices/pending-walk-in-groups');
        $after->assertOk();
        $this->assertCount(0, $after->json('data'));
    }
}
