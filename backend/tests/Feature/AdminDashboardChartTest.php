<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminDashboardChartTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_monthly_revenue_chart_from_payments(): void
    {
        $tz = config('app.timezone');
        Carbon::setTestNow(Carbon::parse('2026-05-20 12:00:00', $tz));

        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $company = Company::query()->create([
            'nombre' => 'Empresa chart',
            'factura_sigla' => 'ECH',
            'nit' => null,
            'estado' => Company::ESTADO_ACTIVO,
        ]);

        $invoice = Invoice::query()->create([
            'code' => 'FAC-CHART-1',
            'company_id' => $company->id,
            'period_year' => 2026,
            'period_month' => 5,
            'status' => Invoice::STATUS_APROBADA,
            'subtotal' => '100000.00',
            'total' => '100000.00',
        ]);

        foreach ([
            ['2026-01-10', '10000.00'],
            ['2026-02-10', '20000.00'],
            ['2026-03-10', '30000.00'],
            ['2026-04-10', '40000.00'],
            ['2026-05-10', '50000.00'],
        ] as [$date, $amount]) {
            Payment::query()->create([
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'payment_date' => $date,
                'method' => 'Transferencia',
            ]);
        }

        $res = $this->getJson('/api/admin/dashboard');
        $res->assertOk()
            ->assertJsonPath('monthly_revenue_chart.categories', ['Ene', 'Feb', 'Mar', 'Abr', 'May'])
            ->assertJsonPath('monthly_revenue_chart.series.0.name', 'Este mes')
            ->assertJsonPath('monthly_revenue_chart.series.0.data', [10000, 20000, 30000, 40000, 50000])
            ->assertJsonPath('monthly_revenue_chart.series.1.name', 'Mes anterior')
            ->assertJsonPath('monthly_revenue_chart.series.1.data', [0, 10000, 20000, 30000, 40000]);

        Carbon::setTestNow();
    }
}
