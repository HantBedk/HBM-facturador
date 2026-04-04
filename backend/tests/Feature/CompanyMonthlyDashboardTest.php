<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyMonthlyDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_company_monthly_dashboard_aggregates_march(): void
    {
        $company = Company::query()->create([
            'nombre' => 'Empresa KPI',
            'factura_sigla' => 'EKP',
            'nit' => null,
            'estado' => Company::ESTADO_ACTIVO,
        ]);

        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        Service::query()->create([
            'company_id' => $company->id,
            'user_id' => $admin->id,
            'client_name' => 'C1',
            'service_type' => 'T',
            'description' => 'Desc',
            'amount' => 100000,
            'service_date' => '2026-03-15',
            'status' => Service::STATUS_ACTIVO,
            'code' => 'SRV-TEST-1',
        ]);

        $invoice = Invoice::query()->create([
            'code' => 'FAC-TEST-1',
            'company_id' => $company->id,
            'period_year' => 2026,
            'period_month' => 3,
            'status' => Invoice::STATUS_APROBADA,
            'subtotal' => '200000.00',
            'total' => '200000.00',
        ]);

        Payment::query()->create([
            'invoice_id' => $invoice->id,
            'amount' => '50000.00',
            'payment_date' => '2026-03-20',
            'method' => 'Transferencia',
        ]);

        $res = $this->getJson('/api/admin/companies/'.$company->id.'/monthly-dashboard?year=2026&month=3');
        $res->assertOk()
            ->assertJsonPath('period.year', 2026)
            ->assertJsonPath('period.month', 3)
            ->assertJsonPath('last_month.services_count', 1)
            ->assertJsonPath('last_month.services_total_amount', '100000.00')
            ->assertJsonPath('last_month.invoices_for_period_count', 1)
            ->assertJsonPath('last_month.invoices_for_period_billed_total', '200000.00')
            ->assertJsonPath('last_month.invoices_for_period_paid_total', '50000.00')
            ->assertJsonPath('last_month.invoices_for_period_balance', '150000.00')
            ->assertJsonPath('last_month.payments_received_in_calendar_month', '50000.00')
            ->assertJsonPath('averages_last_12_months.months_in_sample', 12);
    }

    public function test_non_admin_cannot_access_monthly_dashboard(): void
    {
        $company = Company::query()->create([
            'nombre' => 'Empresa KPI 2',
            'factura_sigla' => 'EK2',
            'nit' => null,
            'estado' => Company::ESTADO_ACTIVO,
        ]);
        $emp = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        Sanctum::actingAs($emp);

        $this->getJson('/api/admin/companies/'.$company->id.'/monthly-dashboard')
            ->assertForbidden();
    }
}
