<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Service;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Support\InvoiceTotalsFromServices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceTotalsFromServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_computes_subtotal_and_iva_from_catalog(): void
    {
        $company = Company::query()->create([
            'nombre' => 'C',
            'factura_sigla' => 'TST',
            'nit' => '900-1',
            'estado' => Company::ESTADO_ACTIVO,
        ]);

        $cat = ServiceCatalog::query()->create([
            'name' => 'A',
            'base_price' => 1,
            'iva_percent' => 10,
            'status' => ServiceCatalog::STATUS_ACTIVO,
        ]);

        $u = User::factory()->create(['rol' => User::ROL_EMPLEADO]);

        $s = Service::query()->create([
            'code' => 'X-1',
            'company_id' => $company->id,
            'user_id' => $u->id,
            'catalog_id' => $cat->id,
            'client_name' => 'K',
            'service_type' => 'T',
            'description' => 'D',
            'amount' => 1000.00,
            'service_date' => '2026-01-10',
            'status' => Service::STATUS_ACTIVO,
        ]);

        $totals = InvoiceTotalsFromServices::fromServiceIds([(int) $s->id]);

        $this->assertSame('1000.00', $totals['subtotal']);
        $this->assertSame('1100.00', $totals['total']);
    }
}
