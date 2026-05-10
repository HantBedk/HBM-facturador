<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminSystemOrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_read_and_update_system_organization(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/settings/system-organization')
            ->assertOk()
            ->assertJsonPath('data.legal_name', '')
            ->assertJsonPath('data.logo_configured', false);

        $this->putJson('/api/admin/settings/system-organization', [
            'legal_name' => 'ACME SAS',
            'trade_name' => 'ACME Servicios',
            'nit' => '900.123-1',
            'email' => 'contacto@acme.test',
            'phone' => '+57 300 123',
            'address_line1' => 'Calle 1 # 2-3',
            'city' => 'Bogotá',
        ])->assertOk()
            ->assertJsonPath('data.trade_name', 'ACME Servicios');

        $row = AppSetting::query()->where('key', AppSetting::KEY_SYSTEM_ORGANIZATION_PROFILE)->first();
        $this->assertIsArray($row->value);
        $this->assertSame('ACME Servicios', $row->value['trade_name']);
    }

    public function test_admin_can_upload_and_delete_logo(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $png = UploadedFile::fake()->image('logo.png', 80, 80);

        $this->postJson('/api/admin/settings/system-organization/logo', [
            'file' => $png,
        ])->assertOk()
            ->assertJsonPath('data.logo_configured', true);

        $this->getJson('/api/admin/settings/system-organization')
            ->assertOk()
            ->assertJsonPath('data.logo_configured', true);

        $this->deleteJson('/api/admin/settings/system-organization/logo')
            ->assertOk()
            ->assertJsonPath('data.logo_configured', false);
    }
}
