<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyRecurringService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyRecurringSpreadsheetTest extends TestCase
{
    use RefreshDatabase;

    private function adminAndCompany(): array
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $company = Company::query()->create([
            'nombre' => 'Cliente SA',
            'factura_sigla' => 'CLI',
            'nit' => '900000001-1',
            'estado' => Company::ESTADO_ACTIVO,
            'es_cliente_puntual' => false,
        ]);

        return compact('admin', 'company');
    }

    public function test_admin_can_download_recurring_template(): void
    {
        $s = $this->adminAndCompany();
        Sanctum::actingAs($s['admin']);

        $this->get('/api/admin/export/recurring-fixed-charges/template')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_import_creates_and_updates_recurring_by_nit_and_description(): void
    {
        $s = $this->adminAndCompany();
        Sanctum::actingAs($s['admin']);

        $csv = "NIT empresa;Descripción;Importe mensual;Tipo;Activo;Orden\n";
        $csv .= "900000001-1;Internet;100000;servicio;si;0\n";

        $file = UploadedFile::fake()->createWithContent('cargos.csv', $csv);

        $r = $this->post('/api/admin/import/recurring-fixed-charges', ['file' => $file]);
        $r->assertOk()
            ->assertJsonPath('imported', 1)
            ->assertJsonPath('updated', 0);

        $row = CompanyRecurringService::query()->where('company_id', $s['company']->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('Internet', $row->description);
        $this->assertSame('100000.00', (string) $row->amount);

        $csv2 = "NIT empresa;Descripción;Importe mensual;Tipo;Activo;Orden\n";
        $csv2 .= "900000001-1;Internet;120000;venta;no;2\n";
        $file2 = UploadedFile::fake()->createWithContent('cargos2.csv', $csv2);

        $r2 = $this->post('/api/admin/import/recurring-fixed-charges', ['file' => $file2]);
        $r2->assertOk()
            ->assertJsonPath('imported', 0)
            ->assertJsonPath('updated', 1);

        $row->refresh();
        $this->assertSame('120000.00', (string) $row->amount);
        $this->assertSame('venta', $row->billing_kind);
        $this->assertFalse($row->is_active);
        $this->assertSame(2, $row->sort_order);
    }

    public function test_export_includes_recurring_rows(): void
    {
        $s = $this->adminAndCompany();
        CompanyRecurringService::query()->create([
            'company_id' => $s['company']->id,
            'billing_kind' => 'servicio',
            'description' => 'Cuota',
            'amount' => 50,
            'is_active' => true,
            'sort_order' => 0,
        ]);
        Sanctum::actingAs($s['admin']);

        $res = $this->get('/api/admin/export/recurring-fixed-charges');
        $res->assertOk();
        $body = $res->streamedContent();
        $this->assertStringContainsString('NIT empresa', $body);
        $this->assertStringContainsString('900000001-1', $body);
        $this->assertStringContainsString('Cuota', $body);
    }
}
