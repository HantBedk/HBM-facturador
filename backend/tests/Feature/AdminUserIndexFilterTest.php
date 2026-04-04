<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminUserIndexFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_users_index_filters_by_user_id(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $target = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        User::factory()->create(['rol' => User::ROL_EMPLEADO]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/users?user_id='.$target->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $target->id);
    }
}
