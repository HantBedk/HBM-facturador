<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminBillingAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_read_billing_automation_defaults_from_config(): void
    {
        config([
            'automation.draft_generation_enabled' => true,
            'automation.draft_generation_day' => 22,
            'automation.draft_generation_period' => 'previous',
        ]);

        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/settings/billing-automation')
            ->assertOk()
            ->assertJsonPath('data.draft_generation_enabled', true)
            ->assertJsonPath('data.draft_generation_day', 22)
            ->assertJsonPath('data.draft_generation_period', 'previous')
            ->assertJsonPath('data.stored_in_database.draft_generation_day', false);
    }

    public function test_admin_can_persist_billing_automation_and_show_overrides_config(): void
    {
        config([
            'automation.draft_generation_enabled' => false,
            'automation.draft_generation_day' => 28,
            'automation.draft_generation_period' => 'current',
        ]);

        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $this->putJson('/api/admin/settings/billing-automation', [
            'current_password' => 'password',
            'draft_generation_enabled' => true,
            'draft_generation_day' => 18,
            'draft_generation_period' => 'current',
        ])
            ->assertOk()
            ->assertJsonPath('data.draft_generation_enabled', true)
            ->assertJsonPath('data.draft_generation_day', 18)
            ->assertJsonPath('data.stored_in_database.draft_generation_day', true);

        $this->assertDatabaseHas('app_settings', [
            'key' => AppSetting::KEY_AUTOMATION_DRAFT_GENERATION_DAY,
        ]);

        $this->getJson('/api/admin/settings/billing-automation')
            ->assertOk()
            ->assertJsonPath('data.draft_generation_day', 18);
    }

    public function test_validation_rejects_day_out_of_range(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $this->putJson('/api/admin/settings/billing-automation', [
            'current_password' => 'password',
            'draft_generation_enabled' => true,
            'draft_generation_day' => 29,
            'draft_generation_period' => 'current',
        ])
            ->assertStatus(422);
    }

    public function test_rejects_wrong_current_password(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $this->putJson('/api/admin/settings/billing-automation', [
            'current_password' => 'mala-clave',
            'draft_generation_enabled' => true,
            'draft_generation_day' => 15,
            'draft_generation_period' => 'current',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    }
}
