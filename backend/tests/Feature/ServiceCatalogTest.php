<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Company;
use App\Models\Service;
use App\Models\ServiceCatalog;
use App\Models\ServiceCatalogSuggestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServiceCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-15 14:30:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_active_catalog_requires_auth(): void
    {
        $this->getJson('/api/service-catalog/active')->assertUnauthorized();
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

        $this->getJson('/api/service-catalog/active')
            ->assertOk()
            ->assertHeader('Cache-Control', 'private, no-store, must-revalidate')
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

        // Importe enviado = referencia técnico; con margen global 10% (por defecto) la factura es técnico ÷ 0,9.
        $r = $this->postJson('/api/services', [
            'company_id' => $company->id,
            'catalog_id' => $id,
            'client_name' => 'Cliente X',
            'service_type' => 'Instalación red',
            'description' => 'Cableado y configuración básica de red local.',
            'amount' => 234000,
        ])->assertCreated();
        $this->assertMatchesRegularExpression('/^SERV-260415\d{2}$/', (string) $r->json('data.code'));

        $svc = Service::query()->first();
        $this->assertSame($id, $svc->catalog_id);
        $this->assertSame('260000.00', (string) $svc->amount);
        $this->assertSame(1, $svc->items()->count());
        $this->assertSame('234000.00', (string) $svc->items()->first()->technician_line_amount);

        $this->putJson('/api/admin/service-catalog/'.$id, [
            'name' => 'Instalación red',
            'description' => 'Cableado y configuración básica de red local.',
            'base_price' => 999999,
            'status' => ServiceCatalog::STATUS_ACTIVO,
        ])->assertOk();

        $svc->refresh();
        $this->assertSame('260000.00', (string) $svc->amount);
    }

    public function test_admin_can_show_single_catalog_item(): void
    {
        $cat = ServiceCatalog::query()->create([
            'name' => 'Item ver uno',
            'description' => 'Detalle vía API.',
            'base_price' => 15000,
            'status' => ServiceCatalog::STATUS_ACTIVO,
        ]);

        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/service-catalog/'.$cat->id)
            ->assertOk()
            ->assertJsonPath('data.id', $cat->id)
            ->assertJsonPath('data.code', 'CAT-'.$cat->id)
            ->assertJsonPath('data.name', 'Item ver uno');
    }

    public function test_empleado_cannot_access_admin_catalog_show(): void
    {
        $cat = ServiceCatalog::query()->create([
            'name' => 'Privado',
            'description' => 'Solo admin.',
            'base_price' => 100,
            'status' => ServiceCatalog::STATUS_ACTIVO,
        ]);

        $emp = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        Sanctum::actingAs($emp);

        $this->getJson('/api/admin/service-catalog/'.$cat->id)->assertForbidden();
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
        ])->assertStatus(422);
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
            'items' => [
                ['catalog_id' => $a->id, 'amount' => 90, 'line_description' => 'Trabajo realizado en línea A según visita.'],
                ['catalog_id' => $b->id, 'amount' => 180, 'line_description' => 'Trabajo realizado en línea B según visita.'],
            ],
        ])->assertCreated();

        $svc = Service::query()->latest('id')->first();
        $this->assertSame('300.00', (string) $svc->amount);
        $this->assertSame(2, $svc->items()->count());
        $items = $svc->items()->orderBy('sort_order')->get();
        $this->assertSame('90.00', (string) $items[0]->technician_line_amount);
        $this->assertSame('100.00', (string) $items[0]->amount);
        $this->assertSame('180.00', (string) $items[1]->technician_line_amount);
        $this->assertSame('200.00', (string) $items[1]->amount);
    }

    public function test_custom_line_never_creates_catalog_suggestion(): void
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
            'items' => [
                [
                    'custom_name' => 'Reparación especial',
                    'custom_description' => 'Detalle de la reparación especial realizada.',
                    'amount' => 50000,
                    'line_description' => 'Detalle de la reparación especial realizada.',
                    'propose_catalog' => true,
                ],
            ],
        ])->assertCreated();

        $this->assertSame(0, ServiceCatalogSuggestion::query()->count());
    }

    public function test_custom_line_without_propose_catalog_does_not_create_suggestion(): void
    {
        Sanctum::actingAs(User::factory()->create(['rol' => User::ROL_EMPLEADO]));

        $company = Company::query()->create([
            'nombre' => 'Emp no prop',
            'factura_sigla' => 'ENP',
            'nit' => '908-8',
            'estado' => Company::ESTADO_ACTIVO,
        ]);

        $this->postJson('/api/services', [
            'company_id' => $company->id,
            'client_name' => 'Cliente',
            'service_type' => 'Algo',
            'description' => 'Descripción larga del servicio realizado en sitio.',
            'items' => [
                [
                    'custom_name' => 'Sin propuesta catálogo',
                    'amount' => 10000,
                    'line_description' => 'Detalle del trabajo sin propuesta de catálogo.',
                ],
            ],
        ])->assertCreated();

        $this->assertSame(0, ServiceCatalogSuggestion::query()->count());
    }

    public function test_custom_line_matching_existing_catalog_name_does_not_create_suggestion(): void
    {
        Sanctum::actingAs(User::factory()->create(['rol' => User::ROL_EMPLEADO]));

        $company = Company::query()->create([
            'nombre' => 'Emp dup',
            'factura_sigla' => 'EDU',
            'nit' => '906-6',
            'estado' => Company::ESTADO_ACTIVO,
        ]);

        ServiceCatalog::query()->create([
            'name' => 'Instalación de software',
            'description' => 'Descripción larga suficiente para el catálogo global.',
            'base_price' => 85000,
            'status' => ServiceCatalog::STATUS_ACTIVO,
        ]);

        $this->postJson('/api/services', [
            'company_id' => $company->id,
            'client_name' => 'Cliente',
            'service_type' => 'Instalación',
            'description' => 'Trabajo en sitio.',
            'items' => [
                [
                    'custom_name' => 'Instalación',
                    'amount' => 350000,
                    'line_description' => 'Instalación personalizada distinta al ítem de catálogo homónimo.',
                    'propose_catalog' => true,
                ],
            ],
        ])->assertCreated();

        $this->assertSame(0, ServiceCatalogSuggestion::query()->where('status', ServiceCatalogSuggestion::STATUS_PENDING)->count());
    }

    public function test_admin_pendientes_lists_all_pending_even_if_name_overlaps_catalog(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_SUPER_ADMIN]);
        Sanctum::actingAs($admin);

        $company = Company::query()->create([
            'nombre' => 'Emp hide',
            'factura_sigla' => 'EHI',
            'nit' => '907-7',
            'estado' => Company::ESTADO_ACTIVO,
        ]);

        ServiceCatalog::query()->create([
            'name' => 'Mantenimiento preventivo',
            'description' => 'Descripción larga del mantenimiento preventivo en catálogo.',
            'base_price' => 95000,
            'status' => ServiceCatalog::STATUS_ACTIVO,
        ]);

        $overlap = ServiceCatalogSuggestion::query()->create([
            'company_id' => $company->id,
            'user_id' => $admin->id,
            'name' => 'Mantenimiento',
            'description' => 'Propuesta que se solapa semánticamente con catálogo; el admin debe poder verla y rechazarla.',
            'suggested_price' => 95000,
            'status' => ServiceCatalogSuggestion::STATUS_PENDING,
        ]);

        $unique = ServiceCatalogSuggestion::query()->create([
            'company_id' => $company->id,
            'user_id' => $admin->id,
            'name' => 'Intalacion Camara',
            'description' => 'Nuevo servicio realmente distinto.',
            'suggested_price' => 299998,
            'status' => ServiceCatalogSuggestion::STATUS_PENDING,
        ]);

        $r = $this->getJson('/api/admin/service-catalog-suggestions?pendientes=1');
        $r->assertOk();
        $ids = collect($r->json('data'))->pluck('id')->all();
        $this->assertContains($unique->id, $ids);
        $this->assertContains($overlap->id, $ids);
        $this->assertCount(2, $ids);
    }

    public function test_service_catalog_line_bills_from_technician_amount_with_global_margin(): void
    {
        AppSetting::setValue(AppSetting::KEY_TECHNICIAN_CATALOG_DISCOUNT_PERCENT, '10');

        $cat = ServiceCatalog::query()->create([
            'name' => 'Ítem precio lista',
            'description' => 'Descripción para test de precio de lista vs descuento visual.',
            'base_price' => 100000,
            'status' => ServiceCatalog::STATUS_ACTIVO,
        ]);

        $company = Company::query()->create([
            'nombre' => 'Emp lista',
            'factura_sigla' => 'ELI',
            'nit' => '911-1',
            'estado' => Company::ESTADO_ACTIVO,
        ]);

        $emp = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        Sanctum::actingAs($emp);

        $this->postJson('/api/services', [
            'company_id' => $company->id,
            'client_name' => 'Cliente',
            'service_type' => 'Ítem precio lista',
            'description' => 'Descripción larga del servicio registrado con monto enviado rebajado (simula UI).',
            'items' => [
                [
                    'catalog_id' => $cat->id,
                    'amount' => 90000,
                    'line_description' => 'Trabajo registrado con monto enviado rebajado (simula UI).',
                ],
            ],
        ])->assertCreated();

        $svc = Service::query()->latest('id')->first();
        $this->assertSame('100000.00', (string) $svc->amount);
        $line = $svc->items()->first();
        $this->assertSame('100000.00', (string) $line->amount);
        $this->assertSame('90000.00', (string) $line->technician_line_amount);
    }

    public function test_custom_otro_line_billed_amount_includes_company_margin(): void
    {
        AppSetting::setValue(AppSetting::KEY_TECHNICIAN_CATALOG_DISCOUNT_PERCENT, '10');

        $company = Company::query()->create([
            'nombre' => 'Emp margen',
            'factura_sigla' => 'EMG',
            'nit' => '912-2',
            'estado' => Company::ESTADO_ACTIVO,
        ]);

        $emp = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        Sanctum::actingAs($emp);

        $this->postJson('/api/services', [
            'company_id' => $company->id,
            'client_name' => 'Cliente',
            'service_type' => 'Otro trabajo',
            'description' => 'Descripción larga del trabajo personalizado para validar margen en factura.',
            'items' => [
                [
                    'custom_name' => 'Trabajo especial',
                    'amount' => 90000,
                    'line_description' => 'Detalle del trabajo.',
                ],
            ],
        ])->assertCreated();

        $svc = Service::query()->latest('id')->first();
        $this->assertSame('100000.00', (string) $svc->amount);
        $line = $svc->items()->first();
        $this->assertSame('90000.00', (string) $line->technician_line_amount);
        $this->assertSame('100000.00', (string) $line->amount);
    }

    public function test_catalog_item_can_override_technician_discount_percent(): void
    {
        AppSetting::setValue(AppSetting::KEY_TECHNICIAN_CATALOG_DISCOUNT_PERCENT, '10');

        $company = Company::query()->create([
            'nombre' => 'Emp override',
            'factura_sigla' => 'EOV',
            'nit' => '912-3',
            'estado' => Company::ESTADO_ACTIVO,
        ]);

        ServiceCatalog::query()->create([
            'name' => 'Ítem 20 pct',
            'description' => 'Descripción larga para ítem con margen distinto al global.',
            'base_price' => 100000,
            'technician_discount_percent' => 20,
            'status' => ServiceCatalog::STATUS_ACTIVO,
        ]);

        $emp = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        Sanctum::actingAs($emp);

        $res = $this->getJson('/api/service-catalog/active')->assertOk();
        $row = collect($res->json('data'))->firstWhere('name', 'Ítem 20 pct');
        $this->assertNotNull($row);
        $this->assertSame('100000.00', (string) $row['base_price']);
        $this->assertArrayNotHasKey('technician_discount_percent', $row);
    }

    public function test_approve_catalog_suggestion_creates_global_catalog_row(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_SUPER_ADMIN]);
        Sanctum::actingAs($admin);

        $company = Company::query()->create([
            'nombre' => 'Emp appr',
            'factura_sigla' => 'EAP',
            'nit' => '909-9',
            'estado' => Company::ESTADO_ACTIVO,
        ]);

        $suggestion = ServiceCatalogSuggestion::query()->create([
            'company_id' => $company->id,
            'user_id' => $admin->id,
            'name' => 'Servicio único aprobación global',
            'description' => 'Descripción larga del ítem nuevo para catálogo global compartido.',
            'suggested_price' => 50000,
            'status' => ServiceCatalogSuggestion::STATUS_PENDING,
        ]);

        $this->postJson('/api/admin/service-catalog-suggestions/'.$suggestion->id.'/approve')->assertOk();

        $cat = ServiceCatalog::query()->where('name', 'Servicio único aprobación global')->first();
        $this->assertNotNull($cat);

        $suggestion->refresh();
        $this->assertSame(ServiceCatalogSuggestion::STATUS_APPROVED, $suggestion->status);
        $this->assertSame($cat->id, (int) $suggestion->resolved_catalog_id);
    }

    public function test_admin_can_import_catalog_from_csv(): void
    {
        if (! class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            self::markTestSkipped('phpoffice/phpspreadsheet no está instalado (composer install / update en backend).');
        }

        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $csv = "Nombre,Precio,Descripción\nServicio CSV import,1500.75,Texto auxiliar\n";
        $file = UploadedFile::fake()->createWithContent('items.csv', $csv);

        $res = $this->post('/api/admin/service-catalog/import', [
            'file' => $file,
        ]);

        $res->assertOk();
        $res->assertJsonPath('imported', 1);
        $res->assertJsonPath('skipped_duplicates', 0);

        $row = ServiceCatalog::query()->where('name', 'Servicio CSV import')->first();
        $this->assertNotNull($row);
        $this->assertSame('1500.75', (string) $row->base_price);
        $this->assertSame('Texto auxiliar', $row->description);
    }
}
