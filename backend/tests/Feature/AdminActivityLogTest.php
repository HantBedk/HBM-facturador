<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            ->assertJsonStructure([
                'scope',
                'date',
                'timezone',
                'limit',
                'groups',
                'calendar' => ['month', 'today', 'dates_with_activity'],
            ])
            ->assertJsonPath('scope', 'all');

        $this->getJson('/api/admin/activity-logs?scope=facturas')
            ->assertOk()
            ->assertJsonPath('scope', 'facturas');

        $data = $this->getJson('/api/admin/activity-logs?scope=facturas')->json('groups');
        $this->assertCount(1, $data);
        $this->assertSame('factura_creada', $data[0]['movimientos'][0]['action'] ?? null);
    }

    public function test_filters_logs_by_calendar_date_in_app_timezone(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        // 2026-03-29 04:00 UTC → 2026-03-28 23:00 America/Bogota (día 28)
        DB::table('activity_logs')->insert([
            'user_id' => $admin->id,
            'action' => 'servicio_creado',
            'description' => 'Día 28 en Bogotá',
            'created_at' => '2026-03-29 04:00:00',
            'updated_at' => '2026-03-29 04:00:00',
        ]);
        // 2026-03-29 06:00 UTC → 2026-03-29 01:00 America/Bogota (día 29)
        DB::table('activity_logs')->insert([
            'user_id' => $admin->id,
            'action' => 'servicio_editado',
            'description' => 'Día 29 en Bogotá',
            'created_at' => '2026-03-29 06:00:00',
            'updated_at' => '2026-03-29 06:00:00',
        ]);

        $g28 = $this->getJson('/api/admin/activity-logs?date=2026-03-28&scope=all')->assertOk()->json('groups');
        $this->assertCount(1, $g28);
        $this->assertSame('servicio_creado', $g28[0]['movimientos'][0]['action'] ?? null);

        $g29 = $this->getJson('/api/admin/activity-logs?date=2026-03-29&scope=all')->assertOk()->json('groups');
        $this->assertCount(1, $g29);
        $this->assertSame('servicio_editado', $g29[0]['movimientos'][0]['action'] ?? null);
    }
}
