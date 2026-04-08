<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PanelNotification;
use App\Models\Service;
use App\Models\ServiceItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TechnicianPaidNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_registers_technician_payment_notifies_empleado(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_SUPER_ADMIN]);
        $empleado = User::factory()->create(['rol' => User::ROL_EMPLEADO, 'estado' => User::ESTADO_ACTIVO]);
        $company = Company::query()->create([
            'nombre' => 'Empresa X',
            'factura_sigla' => 'EMX',
            'nit' => null,
            'estado' => Company::ESTADO_ACTIVO,
        ]);

        $service = Service::query()->create([
            'code' => 'SRV-TEST-1',
            'company_id' => $company->id,
            'user_id' => $empleado->id,
            'catalog_id' => null,
            'client_name' => 'Cliente',
            'service_type' => 'Mantenimiento',
            'description' => 'Test',
            'amount' => '100000.00',
            'service_date' => now()->toDateString(),
            'status' => Service::STATUS_ACTIVO,
            'technician_paid_at' => null,
        ]);
        ServiceItem::query()->create([
            'service_id' => $service->id,
            'catalog_id' => null,
            'label' => 'Línea',
            'amount' => '100000.00',
            'technician_line_amount' => '50000.00',
            'sort_order' => 0,
        ]);

        Sanctum::actingAs($admin);

        $this->patchJson('/api/services/'.$service->id.'/technician-paid', [
            'technician_paid_at' => now()->toDateString(),
        ])->assertOk();

        $this->assertDatabaseHas('panel_notifications', [
            'user_id' => $empleado->id,
            'type' => PanelNotification::TYPE_EMP_ABONO_TECNICO_REGISTRADO,
            'read' => false,
        ]);
    }
}
