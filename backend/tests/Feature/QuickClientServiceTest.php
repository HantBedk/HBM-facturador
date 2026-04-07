<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Service;
use App\Models\ServiceCatalog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuickClientServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_servicio_sin_fila_en_companies_y_reutiliza_mismo_telefono(): void
    {
        $cat = ServiceCatalog::query()->create([
            'name' => 'Prueba cat',
            'description' => 'Desc larga para catálogo de prueba.',
            'base_price' => 100000,
            'status' => ServiceCatalog::STATUS_ACTIVO,
        ]);

        $empleado = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        Sanctum::actingAs($empleado);

        $r1 = $this->postJson('/api/services', [
            'quick_client' => [
                'nombre' => 'Ana Pérez',
                'telefono' => '300 123 4567',
            ],
            'client_name' => 'Ana Pérez',
            'service_type' => 'Prueba cat',
            'description' => 'Detalle del trabajo realizado con suficiente texto para validar.',
            'items' => [
                [
                    'catalog_id' => $cat->id,
                    'amount' => 50000,
                    'line_description' => 'Trabajo detallado en sitio con más de ocho letras.',
                ],
            ],
        ]);
        $r1->assertCreated();
        $this->assertNull($r1->json('data.company_id'));

        $this->assertSame(0, Company::query()->where('es_cliente_puntual', true)->count());

        $s1 = Service::query()->findOrFail((int) $r1->json('data.id'));
        $this->assertNull($s1->company_id);
        $this->assertSame('3001234567', $s1->contact_phone_key);

        $r2 = $this->postJson('/api/services', [
            'quick_client' => [
                'nombre' => 'Ana Pérez',
                'telefono' => '+57 300 123 4567',
            ],
            'client_name' => 'Ana Pérez',
            'service_type' => 'Otro',
            'description' => 'Segundo servicio mismo teléfono distinto detalle suficiente.',
            'items' => [
                [
                    'catalog_id' => $cat->id,
                    'amount' => 40000,
                    'line_description' => 'Otra visita con descripción suficientemente larga.',
                ],
            ],
        ]);
        $r2->assertCreated();
        $this->assertNull($r2->json('data.company_id'));

        $s2 = Service::query()->findOrFail((int) $r2->json('data.id'));
        $this->assertSame($s1->contact_phone_key, $s2->contact_phone_key);

        $this->assertSame(0, Company::query()->where('es_cliente_puntual', true)->count());
    }

    public function test_no_permite_company_id_junto_a_quick_client(): void
    {
        $c = Company::query()->create([
            'nombre' => 'Co',
            'factura_sigla' => 'COZ',
            'nit' => '900111222-4',
            'estado' => Company::ESTADO_ACTIVO,
        ]);
        $cat = ServiceCatalog::query()->create([
            'name' => 'Item',
            'description' => 'Desc larga para catálogo de prueba.',
            'base_price' => 100000,
            'status' => ServiceCatalog::STATUS_ACTIVO,
        ]);

        Sanctum::actingAs(User::factory()->create(['rol' => User::ROL_EMPLEADO]));

        $this->postJson('/api/services', [
            'company_id' => $c->id,
            'quick_client' => ['nombre' => 'X', 'telefono' => '3009998877'],
            'client_name' => 'X',
            'service_type' => 'Item',
            'description' => 'Texto largo suficiente para pasar validación mínima.',
            'items' => [
                [
                    'catalog_id' => $cat->id,
                    'amount' => 50000,
                    'line_description' => 'Detalle de línea con más de ocho caracteres.',
                ],
            ],
        ])->assertStatus(422);
    }
}
