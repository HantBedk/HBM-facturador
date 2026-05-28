<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Invoice;
use App\Models\User;
use App\Services\SystemOrganizationProfileService;
use App\Support\InvoicePdfPayload;
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
            'tax_regimen' => 'Régimen simplificado',
        ])->assertOk()
            ->assertJsonPath('data.trade_name', 'ACME Servicios')
            ->assertJsonPath('data.invoice_emitter.ready', true);

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

    public function test_invoice_pdf_issuer_uses_system_organization_profile(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        app(SystemOrganizationProfileService::class)->persist([
            'legal_name' => 'Operador Legal',
            'trade_name' => 'Operador Comercial',
            'nit' => '900.999-1',
            'email' => 'facturacion@operador.test',
            'phone' => '6011111111',
            'address_line1' => 'Carrera 1',
            'city' => 'Medellín',
            'tax_regimen' => 'Responsable de IVA',
        ]);

        $invoice = Invoice::query()->create([
            'code' => 'T-FAC-EMISOR',
            'company_id' => null,
            'period_month' => 3,
            'period_year' => 2026,
            'status' => Invoice::STATUS_APROBADA,
            'subtotal' => 100000.00,
            'total' => 100000.00,
            'sent_at' => null,
        ]);
        $payload = InvoicePdfPayload::build($invoice);

        $this->assertSame('Operador Comercial', $payload['issuer']['nombre']);
        $this->assertSame('900.999-1', $payload['issuer']['nit']);
        $this->assertStringContainsString('Carrera 1', $payload['issuer']['direccion']);
        $this->assertSame('6011111111', $payload['issuer']['telefono']);
        $this->assertSame('facturacion@operador.test', $payload['issuer']['correo']);
        $this->assertSame('Responsable de IVA', $payload['issuer']['regimen']);
    }
}
