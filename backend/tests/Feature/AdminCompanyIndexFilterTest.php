<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminCompanyIndexFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_filters_by_company_kind(): void
    {
        $registered = Company::query()->create([
            'nombre' => 'Reg S.A.',
            'factura_sigla' => 'REG',
            'nit' => null,
            'estado' => Company::ESTADO_ACTIVO,
            'es_cliente_puntual' => false,
        ]);
        $legacy = Company::query()->create([
            'nombre' => 'Legacy puntual',
            'factura_sigla' => 'QUI',
            'nit' => null,
            'estado' => Company::ESTADO_ACTIVO,
            'es_cliente_puntual' => true,
        ]);

        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $registeredRes = $this->getJson('/api/admin/companies?company_kind=registered')->assertOk();
        $registeredIds = collect($registeredRes->json('data'))->pluck('id')->all();
        $this->assertContains($registered->id, $registeredIds);
        $this->assertNotContains($legacy->id, $registeredIds);

        $quickRes = $this->getJson('/api/admin/companies?company_kind=quick')->assertOk();
        $quickIds = collect($quickRes->json('data'))->pluck('id')->all();
        $this->assertContains($legacy->id, $quickIds);
        $this->assertNotContains($registered->id, $quickIds);

        $allRes = $this->getJson('/api/admin/companies')->assertOk();
        $allIds = collect($allRes->json('data'))->pluck('id')->all();
        $this->assertContains($registered->id, $allIds);
        $this->assertContains($legacy->id, $allIds);
    }
}
