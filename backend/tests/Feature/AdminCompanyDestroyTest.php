<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminCompanyDestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_delete_company_without_services(): void
    {
        $company = Company::query()->create([
            'nombre' => 'Solo facturable',
            'factura_sigla' => 'ZZZ',
            'nit' => null,
            'estado' => Company::ESTADO_ACTIVO,
        ]);
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $this->deleteJson('/api/admin/companies/'.$company->id)
            ->assertNoContent();

        $this->assertDatabaseMissing('companies', ['id' => $company->id]);
    }

    public function test_cannot_delete_company_with_services(): void
    {
        $company = Company::query()->create([
            'nombre' => 'Con servicios',
            'factura_sigla' => 'CSV',
            'nit' => null,
            'estado' => Company::ESTADO_ACTIVO,
        ]);
        $user = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        Service::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'client_name' => 'C',
            'service_type' => 'T',
            'description' => 'D',
            'amount' => 1000,
            'service_date' => '2026-04-01',
            'status' => Service::STATUS_ACTIVO,
            'code' => 'DEL-TEST-SRV',
        ]);
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $res = $this->deleteJson('/api/admin/companies/'.$company->id)->assertUnprocessable();
        $this->assertStringContainsString('servicios', (string) $res->json('errors.company.0'));

        $this->assertDatabaseHas('companies', ['id' => $company->id]);
    }
}
