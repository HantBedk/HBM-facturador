<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_empleado_cannot_list_activity_logs(): void
    {
        $emp = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        Sanctum::actingAs($emp);

        $this->getJson('/api/admin/activity-logs')->assertForbidden();
    }

    public function test_admin_gets_grouped_logs_and_facturas_scope(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $tech = User::factory()->create(['rol' => User::ROL_EMPLEADO]);

        ActivityLog::query()->create([
            'user_id' => $admin->id,
            'action' => 'factura_creada',
            'description' => 'Creó factura X.',
        ]);
        ActivityLog::query()->create([
            'user_id' => $tech->id,
            'action' => 'servicio_creado',
            'description' => 'Creó servicio Y.',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/activity-logs?scope=all')
            ->assertOk()
            ->assertJsonStructure(['scope', 'limit', 'groups'])
            ->assertJsonPath('scope', 'all');

        $this->getJson('/api/admin/activity-logs?scope=facturas')
            ->assertOk()
            ->assertJsonPath('scope', 'facturas');

        $data = $this->getJson('/api/admin/activity-logs?scope=facturas')->json('groups');
        $this->assertCount(1, $data);
        $this->assertSame('factura_creada', $data[0]['movimientos'][0]['action'] ?? null);
    }
}
