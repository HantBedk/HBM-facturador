<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\InventoryLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PublicCompanyInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_inventory_otp_verify_and_list_without_prices(): void
    {
        Mail::fake();
        Cache::flush();

        $company = Company::query()->create([
            'nombre' => 'Empresa Portal',
            'factura_sigla' => 'EPR',
            'nit' => '901.234.567-8',
            'estado' => Company::ESTADO_ACTIVO,
            'correo' => 'contacto@empresa-portal.test',
        ]);

        InventoryLot::query()->create([
            'owner_user_id' => User::factory()->create()->id,
            'tenant_company_id' => $company->id,
            'sku' => 'TST-1',
            'name' => 'Router test',
            'description' => 'test',
            'quantity_available' => 2,
            'unit_price' => '150000.00',
            'is_active' => true,
            'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
            'allow_sale' => false,
            'allow_rental' => false,
        ]);

        $this->postJson('/api/public/company-inventory/otp/request', [
            'nit' => '9012345678',
            'email' => 'contacto@empresa-portal.test',
        ])->assertOk()->assertJsonPath('expires_in', 600);

        $nitKey = '9012345678';
        $emailKey = 'contacto@empresa-portal.test';
        $cacheKey = 'company_inv_otp:'.hash('sha256', $nitKey.'|'.$emailKey.'|'.config('app.key'));
        $cached = Cache::get($cacheKey);
        $this->assertIsArray($cached);
        $otp = $cached['otp'];

        $verify = $this->postJson('/api/public/company-inventory/otp/verify', [
            'nit' => '9012345678',
            'email' => 'contacto@empresa-portal.test',
            'code' => $otp,
        ])->assertOk();

        $token = (string) $verify->json('access_token');
        $this->assertNotSame('', $token);

        $list = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/public/company-inventory/lots')
            ->assertOk();

        $list->assertJsonCount(1, 'data');
        $row = $list->json('data.0');
        $this->assertSame('Router test', $row['name']);
        $this->assertArrayNotHasKey('unit_price', $row);
    }

    public function test_verify_otp_rejects_invalid_code(): void
    {
        Cache::flush();

        $company = Company::query()->create([
            'nombre' => 'Empresa OTP',
            'factura_sigla' => 'EOT',
            'nit' => '800111222',
            'estado' => Company::ESTADO_ACTIVO,
            'correo' => 'otp@empresa.test',
        ]);

        $this->postJson('/api/public/company-inventory/otp/request', [
            'nit' => '800111222',
            'email' => 'otp@empresa.test',
        ])->assertOk();

        $nitKey = '800111222';
        $emailKey = 'otp@empresa.test';
        $cacheKey = 'company_inv_otp:'.hash('sha256', $nitKey.'|'.$emailKey.'|'.config('app.key'));
        $this->assertNotNull(Cache::get($cacheKey));

        $this->postJson('/api/public/company-inventory/otp/verify', [
            'nit' => '800111222',
            'email' => 'otp@empresa.test',
            'code' => '000000',
        ])->assertUnprocessable();
    }

    public function test_public_token_only_lists_own_company_lots(): void
    {
        Mail::fake();
        Cache::flush();

        $c1 = Company::query()->create([
            'nombre' => 'C1',
            'factura_sigla' => 'CAA',
            'nit' => '111',
            'estado' => Company::ESTADO_ACTIVO,
            'correo' => 'a@c1.test',
        ]);
        $c2 = Company::query()->create([
            'nombre' => 'C2',
            'factura_sigla' => 'CBB',
            'nit' => '222',
            'estado' => Company::ESTADO_ACTIVO,
            'correo' => 'b@c2.test',
        ]);

        $user = User::factory()->create();

        InventoryLot::query()->create([
            'owner_user_id' => $user->id,
            'tenant_company_id' => $c1->id,
            'sku' => 'A1',
            'name' => 'Solo C1',
            'description' => null,
            'quantity_available' => 1,
            'unit_price' => '1.00',
            'is_active' => true,
            'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
            'allow_sale' => false,
            'allow_rental' => false,
        ]);
        InventoryLot::query()->create([
            'owner_user_id' => $user->id,
            'tenant_company_id' => $c2->id,
            'sku' => 'B1',
            'name' => 'Solo C2',
            'description' => null,
            'quantity_available' => 1,
            'unit_price' => '1.00',
            'is_active' => true,
            'lifecycle_status' => InventoryLot::LIFECYCLE_ACTIVO,
            'allow_sale' => false,
            'allow_rental' => false,
        ]);

        $this->postJson('/api/public/company-inventory/otp/request', [
            'nit' => '111',
            'email' => 'a@c1.test',
        ])->assertOk();

        $cacheKey = 'company_inv_otp:'.hash('sha256', '111|a@c1.test|'.config('app.key'));
        $otp = Cache::get($cacheKey)['otp'];

        $tok = $this->postJson('/api/public/company-inventory/otp/verify', [
            'nit' => '111',
            'email' => 'a@c1.test',
            'code' => $otp,
        ])->assertOk()->json('access_token');

        $this->withHeader('Authorization', 'Bearer '.$tok)
            ->getJson('/api/public/company-inventory/lots')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Solo C1');
    }
}
