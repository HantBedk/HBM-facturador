<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Service;
use App\Models\ServiceCatalog;
use App\Models\ServiceCatalogSuggestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServiceCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_catalog_requires_auth(): void
    {
        $this->getJson('/api/service-catalog/active?company_id=1')->assertUnauthorized();
    }

    public function test_empleado_can_list_active_catalog(): void
    {
        $company = Company::query()->create([
            'nombre' => 'EmpCat',
            'factura_sigla' => 'ECA',
            'nit' => '903-3',
            'estado' => Company::ESTADO_ACTIVO,
        ]);

        ServiceCatalog::query()->create([
            'name' => 'Prueba cat',
            'description' => 'Descripción larga para validación.',
            'base_price' => 10000,
            'status' => ServiceCatalog::STATUS_ACTIVO,
        ]);

        $emp = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        Sanctum::actingAs($emp);

        $this->getJson('/api/service-catalog/active?company_id='.$company->id)
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
            'factura_sigla' => 'EMP',
            'nit' => '901-1',
            'estado' => Company::ESTADO_ACTIVO,
        ]);
        $emp = User::factory()->create(['rol' => User::ROL_EMPLEADO]);

        $r = $this->postJson('/api/services', [
            'company_id' => $company->id,
            'catalog_id' => $id,
            'client_name' => 'Cliente X',
            'service_type' => 'Instalación red',
            'description' => 'Cableado y configuración básica de red local.',
            'amount' => 260000,
            'service_date' => '2026-04-15',
        ])->assertCreated();
        $this->assertMatchesRegularExpression('/^SERV-260415\d{2}$/', (string) $r->json('data.code'));

        $svc = Service::query()->first();
        $this->assertSame($id, $svc->catalog_id);
        $this->assertSame('260000.00', (string) $svc->amount);
        $this->assertSame(1, $svc->items()->count());

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
            'factura_sigla' => 'EMQ',
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

    public function test_active_catalog_requires_company_id(): void
    {
        $emp = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        Sanctum::actingAs($emp);

        $this->getJson('/api/service-catalog/active')->assertStatus(422);
    }

    public function test_service_with_items_payload_sums_amounts(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $a = ServiceCatalog::query()->create([
            'name' => 'Línea A',
            'description' => 'Descripción larga de la línea A para cumplir validación.',
            'base_price' => 100,
            'status' => ServiceCatalog::STATUS_ACTIVO,
        ]);
        $b = ServiceCatalog::query()->create([
            'name' => 'Línea B',
            'description' => 'Descripción larga de la línea B para cumplir validación.',
            'base_price' => 200,
            'status' => ServiceCatalog::STATUS_ACTIVO,
        ]);

        $company = Company::query()->create([
            'nombre' => 'Emp multi',
            'factura_sigla' => 'EMU',
            'nit' => '904-4',
            'estado' => Company::ESTADO_ACTIVO,
        ]);

        $this->postJson('/api/services', [
            'company_id' => $company->id,
            'client_name' => 'Cliente Y',
            'service_type' => 'Línea A · Línea B',
            'description' => 'Descripción general del servicio con varias líneas.',
            'service_date' => '2026-04-15',
            'items' => [
                ['catalog_id' => $a->id, 'amount' => 100],
                ['catalog_id' => $b->id, 'amount' => 200],
            ],
        ])->assertCreated();

        $svc = Service::query()->latest('id')->first();
        $this->assertSame('300.00', (string) $svc->amount);
        $this->assertSame(2, $svc->items()->count());
    }

    public function test_custom_line_creates_pending_suggestion(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $company = Company::query()->create([
            'nombre' => 'Emp sug',
            'factura_sigla' => 'ESU',
            'nit' => '905-5',
            'estado' => Company::ESTADO_ACTIVO,
        ]);

        $this->postJson('/api/services', [
            'company_id' => $company->id,
            'client_name' => 'Cliente Z',
            'service_type' => 'Otro especial',
            'description' => 'Descripción general del trabajo realizado en sitio.',
            'service_date' => '2026-04-16',
            'items' => [
                [
                    'custom_name' => 'Reparación especial',
                    'custom_description' => 'Detalle de la reparación especial realizada.',
                    'amount' => 50000,
                ],
            ],
        ])->assertCreated();

        $this->assertSame(1, ServiceCatalogSuggestion::query()->where('status', ServiceCatalogSuggestion::STATUS_PENDING)->count());
    }
}
