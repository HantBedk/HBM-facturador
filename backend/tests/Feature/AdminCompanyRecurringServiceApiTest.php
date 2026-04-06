<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyRecurringService;
use App\Models\ServiceCatalog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminCompanyRecurringServiceApiTest extends TestCase
{
    use RefreshDatabase;

    private function seed(): array
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $company = Company::query()->create([
            'nombre' => 'Cliente SA',
            'factura_sigla' => 'CLI',
            'nit' => '900000001-1',
            'estado' => Company::ESTADO_ACTIVO,
        ]);
        $cat = ServiceCatalog::query()->create([
            'name' => 'Cuota plataforma',
            'description' => null,
            'base_price' => 0,
            'status' => ServiceCatalog::STATUS_ACTIVO,
        ]);

        return compact('admin', 'company', 'cat');
    }

    public function test_admin_can_list_create_update_and_delete_recurring_lines(): void
    {
        $s = $this->seed();
        Sanctum::actingAs($s['admin']);

        $list = $this->getJson('/api/admin/companies/'.$s['company']->id.'/recurring-services');
        $list->assertOk()->assertJsonCount(0, 'data');

        $create = $this->postJson('/api/admin/companies/'.$s['company']->id.'/recurring-services', [
            'catalog_id' => $s['cat']->id,
            'amount' => 99.5,
            'description' => 'Mensual',
            'is_active' => true,
        ]);
        $create->assertCreated()
            ->assertJsonPath('data.amount', '99.50');

        $id = (int) $create->json('data.id');
        $this->assertGreaterThan(0, $id);

        $update = $this->putJson(
            '/api/admin/companies/'.$s['company']->id.'/recurring-services/'.$id,
            ['amount' => 150, 'is_active' => false]
        );
        $update->assertOk()
            ->assertJsonPath('data.amount', '150.00')
            ->assertJsonPath('data.is_active', false);

        $this->deleteJson('/api/admin/companies/'.$s['company']->id.'/recurring-services/'.$id)
            ->assertNoContent();

        $this->getJson('/api/admin/companies/'.$s['company']->id.'/recurring-services')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_update_returns_404_when_recurring_belongs_to_other_company(): void
    {
        $s = $this->seed();
        $other = Company::query()->create([
            'nombre' => 'Otra',
            'factura_sigla' => 'OTR',
            'nit' => '900000002-1',
            'estado' => Company::ESTADO_ACTIVO,
        ]);
        $row = CompanyRecurringService::query()->create([
            'company_id' => $other->id,
            'catalog_id' => $s['cat']->id,
            'description' => 'X',
            'amount' => 10,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        Sanctum::actingAs($s['admin']);

        $this->putJson(
            '/api/admin/companies/'.$s['company']->id.'/recurring-services/'.$row->id,
            ['amount' => 20]
        )->assertNotFound();
    }
}
