<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminMailNotificationsSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_read_mail_notifications_settings(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/settings/mail-notifications')
            ->assertOk()
            ->assertJsonStructure(['data' => ['from_address', 'from_name', 'effective_from_address', 'effective_from_name'], 'help']);
    }

    public function test_admin_can_update_mail_from_with_valid_password(): void
    {
        $admin = User::factory()->create([
            'rol' => User::ROL_ADMIN,
            'password' => Hash::make('SecretPass-99'),
        ]);
        Sanctum::actingAs($admin);

        $this->putJson('/api/admin/settings/mail-notifications', [
            'current_password' => 'SecretPass-99',
            'from_address' => 'facturacion@cliente-hbm.test',
            'from_name' => 'HBM Facturación',
        ])->assertOk()
            ->assertJsonPath('data.from_address', 'facturacion@cliente-hbm.test')
            ->assertJsonPath('data.from_name', 'HBM Facturación')
            ->assertJsonPath('data.effective_from_address', 'facturacion@cliente-hbm.test');

        $row = AppSetting::query()->where('key', AppSetting::KEY_MAIL_NOTIFICATIONS_FROM)->first();
        $this->assertIsArray($row->value);
        $this->assertSame('facturacion@cliente-hbm.test', $row->value['address']);
    }

    public function test_update_rejects_wrong_password(): void
    {
        $admin = User::factory()->create([
            'rol' => User::ROL_ADMIN,
            'password' => Hash::make('GoodPass-1'),
        ]);
        Sanctum::actingAs($admin);

        $this->putJson('/api/admin/settings/mail-notifications', [
            'current_password' => 'wrong',
            'from_address' => 'x@y.com',
            'from_name' => 'X',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);
    }
}
