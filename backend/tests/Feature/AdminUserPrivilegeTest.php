<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminUserPrivilegeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_index_only_lists_empleados(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        User::factory()->create(['rol' => User::ROL_SUPER_ADMIN, 'correo' => 'sa@test.local']);
        User::factory()->create(['rol' => User::ROL_EMPLEADO, 'correo' => 'emp@test.local']);

        Sanctum::actingAs($admin);
        $r = $this->getJson('/api/admin/users');

        $r->assertOk();
        $data = $r->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('empleado', $data[0]['rol']);
    }

    public function test_super_admin_index_lists_all_roles(): void
    {
        $super = User::factory()->create(['rol' => User::ROL_SUPER_ADMIN]);
        User::factory()->create(['rol' => User::ROL_ADMIN, 'correo' => 'a2@test.local']);
        User::factory()->create(['rol' => User::ROL_EMPLEADO, 'correo' => 'e2@test.local']);

        Sanctum::actingAs($super);
        $r = $this->getJson('/api/admin/users');

        $r->assertOk();
        $this->assertGreaterThanOrEqual(3, count($r->json('data')));
    }

    public function test_admin_cannot_update_another_admin(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $otherAdmin = User::factory()->create(['rol' => User::ROL_ADMIN, 'correo' => 'other@test.local']);

        Sanctum::actingAs($admin);
        $this->putJson('/api/admin/users/'.$otherAdmin->id, [
            'nombre' => 'X',
            'correo' => $otherAdmin->correo,
            'rol' => User::ROL_ADMIN,
            'estado' => User::ESTADO_ACTIVO,
        ])->assertForbidden();
    }

    public function test_admin_cannot_store_admin_role(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);

        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/users', [
            'nombre' => 'Nuevo',
            'correo' => 'nuevo@test.local',
            'password' => 'password123',
            'rol' => User::ROL_ADMIN,
            'estado' => User::ESTADO_ACTIVO,
        ])->assertStatus(422);
    }

    public function test_super_admin_can_store_admin_role(): void
    {
        $super = User::factory()->create(['rol' => User::ROL_SUPER_ADMIN]);

        Sanctum::actingAs($super);
        $this->postJson('/api/admin/users', [
            'nombre' => 'Nuevo admin',
            'correo' => 'nadm@test.local',
            'password' => 'password123',
            'rol' => User::ROL_ADMIN,
            'estado' => User::ESTADO_ACTIVO,
        ])->assertCreated();
    }
}
