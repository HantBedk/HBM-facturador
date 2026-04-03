<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Service;
use App\Models\ServiceCatalog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServiceCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_catalog_requires_auth(): void
    {
        $this->getJson('/api/service-catalog/active')->assertUnauthorized();
    }

    public function test_empleado_can_list_active_catalog(): void
    {
        ServiceCatalog::query()->create([
            'name' => 'Prueba cat',
            'description' => 'Descripción larga para validación.',
            'base_price' => 10000,
            'status' => ServiceCatalog::STATUS_ACTIVO,
        ]);

        $emp = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        Sanctum::actingAs($emp);

        $this->getJson('/api/service-catalog/active')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_admin_can_crud_catalog_and_service_stores_catalog_id(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $r = $this->postJson('/api/admin/service-catalog', [
            'name' => 'Instalación red',
            'description' => 'Cableado y configuración básica de red local.',
            'base_price' => 250000,
        ]);
        $r->assertCreated();
        $id = $r->json('data.id');

        $this->putJson('/api/admin/service-catalog/'.$id, [
            'name' => 'Instalación red',
            'description' => 'Cableado y configuración básica de red local.',
            'base_price' => 260000,
            'status' => ServiceCatalog::STATUS_ACTIVO,
        ])->assertOk();

        $company = Company::query()->create([
            'nombre' => 'Emp',
            'nit' => '901-1',
            'estado' => Company::ESTADO_ACTIVO,
        ]);
        $emp = User::factory()->create(['rol' => User::ROL_EMPLEADO]);

        $this->postJson('/api/services', [
            'company_id' => $company->id,
            'catalog_id' => $id,
            'client_name' => 'Cliente X',
            'service_type' => 'Instalación red',
            'description' => 'Cableado y configuración básica de red local.',
            'amount' => 260000,
            'service_date' => '2026-04-15',
        ])->assertCreated();

        $svc = Service::query()->first();
        $this->assertSame($id, $svc->catalog_id);
        $this->assertSame('260000.00', (string) $svc->amount);

        $this->putJson('/api/admin/service-catalog/'.$id, [
            'name' => 'Instalación red',
            'description' => 'Cableado y configuración básica de red local.',
            'base_price' => 999999,
            'status' => ServiceCatalog::STATUS_ACTIVO,
        ])->assertOk();

        $svc->refresh();
        $this->assertSame('260000.00', (string) $svc->amount);
    }

    public function test_inactive_catalog_cannot_be_used_on_new_service(): void
    {
        $cat = ServiceCatalog::query()->create([
            'name' => 'Off',
            'description' => 'Descripción larga del ítem inactivo.',
            'base_price' => 1000,
            'status' => ServiceCatalog::STATUS_INACTIVO,
        ]);

        $company = Company::query()->create([
            'nombre' => 'Emp',
            'nit' => '902-2',
            'estado' => Company::ESTADO_ACTIVO,
        ]);
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/services', [
            'company_id' => $company->id,
            'catalog_id' => $cat->id,
            'client_name' => 'Cliente X',
            'service_type' => 'Off',
            'description' => 'Descripción larga del ítem inactivo.',
            'amount' => 1000,
            'service_date' => '2026-04-15',
        ])->assertStatus(422);
    }
}
