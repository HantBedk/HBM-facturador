<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminListSortTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_users_index_accepts_sort_params(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_SUPER_ADMIN]);
        User::factory()->create(['nombre' => 'Ana', 'correo' => 'ana@example.com']);
        User::factory()->create(['nombre' => 'Zoe', 'correo' => 'zoe@example.com']);

        $r = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users?sort=nombre&sort_dir=desc&per_page=50');

        $r->assertOk();
        $names = collect($r->json('data'))->pluck('nombre')->all();
        $posZoe = array_search('Zoe', $names, true);
        $posAna = array_search('Ana', $names, true);
        $this->assertNotFalse($posZoe);
        $this->assertNotFalse($posAna);
        $this->assertLessThan($posAna, $posZoe, 'nombre desc: Zoe debe ir antes que Ana');
    }

    public function test_admin_invoices_index_accepts_sort_by_code(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_SUPER_ADMIN]);
        $company = Company::query()->create([
            'nombre' => 'Empresa Sort',
            'factura_sigla' => 'SOR',
            'nit' => '900111333-1',
            'estado' => Company::ESTADO_ACTIVO,
        ]);

        Invoice::query()->create([
            'code' => 'FAC-ZZZ',
            'company_id' => $company->id,
            'period_year' => 2026,
            'period_month' => 1,
            'status' => Invoice::STATUS_BORRADOR,
            'subtotal' => '100.00',
            'total' => '100.00',
        ]);
        Invoice::query()->create([
            'code' => 'FAC-AAA',
            'company_id' => $company->id,
            'period_year' => 2026,
            'period_month' => 2,
            'status' => Invoice::STATUS_BORRADOR,
            'subtotal' => '200.00',
            'total' => '200.00',
        ]);

        $r = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/invoices?sort=code&sort_dir=asc&per_page=50');

        $r->assertOk();
        $codes = collect($r->json('data'))->pluck('code')->all();
        $this->assertSame('FAC-AAA', $codes[0]);
        $this->assertSame('FAC-ZZZ', $codes[1]);
    }
}
