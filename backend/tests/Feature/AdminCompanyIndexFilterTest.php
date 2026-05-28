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

    public function test_index_excludes_walk_in_companies(): void
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

        $res = $this->getJson('/api/admin/companies')->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($registered->id, $ids);
        $this->assertNotContains($legacy->id, $ids);
    }
}
