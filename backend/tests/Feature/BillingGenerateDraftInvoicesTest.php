<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class BillingGenerateDraftInvoicesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_creates_draft_on_configured_day_for_current_period(): void
    {
        config([
            'automation.draft_generation_enabled' => true,
            'automation.draft_generation_day' => 28,
            'automation.draft_generation_period' => 'current',
        ]);

        $tz = config('app.timezone');
        Carbon::setTestNow(Carbon::parse('2026-03-28 10:00:00', $tz));

        $company = Company::query()->create([
            'nombre' => 'Empresa Auto',
            'factura_sigla' => 'AUT',
            'nit' => '900111333-1',
            'estado' => Company::ESTADO_ACTIVO,
        ]);

        $empleado = User::factory()->create(['rol' => User::ROL_EMPLEADO]);

        Service::query()->create([
            'code' => 'AUTO-SVC-1',
            'company_id' => $company->id,
            'user_id' => $empleado->id,
            'client_name' => 'Cliente',
            'service_type' => 'Obra',
            'description' => 'Servicio mes en curso',
            'amount' => 50000.00,
            'service_date' => '2026-03-10',
            'status' => Service::STATUS_ACTIVO,
        ]);

        Artisan::call('billing:generate-draft-invoices');

        $this->assertSame(1, Invoice::query()->count());
        $inv = Invoice::query()->first();
        $this->assertSame(Invoice::STATUS_BORRADOR, $inv->status);
        $this->assertSame(3, (int) $inv->period_month);
        $this->assertSame(2026, (int) $inv->period_year);
        $this->assertStringStartsWith('FAC-', $inv->code);
    }

    public function test_skips_when_no_matching_day(): void
    {
        config([
            'automation.draft_generation_enabled' => true,
            'automation.draft_generation_day' => 28,
            'automation.draft_generation_period' => 'current',
        ]);

        $tz = config('app.timezone');
        Carbon::setTestNow(Carbon::parse('2026-03-27 10:00:00', $tz));

        $company = Company::query()->create([
            'nombre' => 'Empresa Auto',
            'factura_sigla' => 'AUT',
            'nit' => '900111333-1',
            'estado' => Company::ESTADO_ACTIVO,
        ]);
        $empleado = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        Service::query()->create([
            'code' => 'AUTO-SVC-1',
            'company_id' => $company->id,
            'user_id' => $empleado->id,
            'client_name' => 'Cliente',
            'service_type' => 'Obra',
            'description' => 'Servicio',
            'amount' => 50000.00,
            'service_date' => '2026-03-10',
            'status' => Service::STATUS_ACTIVO,
        ]);

        Artisan::call('billing:generate-draft-invoices');

        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_skips_when_disabled(): void
    {
        config([
            'automation.draft_generation_enabled' => false,
            'automation.draft_generation_day' => 28,
            'automation.draft_generation_period' => 'current',
        ]);

        $tz = config('app.timezone');
        Carbon::setTestNow(Carbon::parse('2026-03-28 10:00:00', $tz));

        $company = Company::query()->create([
            'nombre' => 'Empresa Auto',
            'factura_sigla' => 'AUT',
            'nit' => '900111333-1',
            'estado' => Company::ESTADO_ACTIVO,
        ]);
        $empleado = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        Service::query()->create([
            'code' => 'AUTO-SVC-1',
            'company_id' => $company->id,
            'user_id' => $empleado->id,
            'client_name' => 'Cliente',
            'service_type' => 'Obra',
            'description' => 'Servicio',
            'amount' => 50000.00,
            'service_date' => '2026-03-10',
            'status' => Service::STATUS_ACTIVO,
        ]);

        Artisan::call('billing:generate-draft-invoices');

        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_skips_when_borrador_already_exists_for_period(): void
    {
        config([
            'automation.draft_generation_enabled' => true,
            'automation.draft_generation_day' => 28,
            'automation.draft_generation_period' => 'current',
        ]);

        $tz = config('app.timezone');
        Carbon::setTestNow(Carbon::parse('2026-03-28 10:00:00', $tz));

        $company = Company::query()->create([
            'nombre' => 'Empresa Auto',
            'factura_sigla' => 'AUT',
            'nit' => '900111333-1',
            'estado' => Company::ESTADO_ACTIVO,
        ]);
        $empleado = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        $service = Service::query()->create([
            'code' => 'AUTO-SVC-1',
            'company_id' => $company->id,
            'user_id' => $empleado->id,
            'client_name' => 'Cliente',
            'service_type' => 'Obra',
            'description' => 'Servicio',
            'amount' => 50000.00,
            'service_date' => '2026-03-10',
            'status' => Service::STATUS_ACTIVO,
        ]);

        Invoice::query()->create([
            'code' => 'EXISTING-001',
            'company_id' => $company->id,
            'period_month' => 3,
            'period_year' => 2026,
            'status' => Invoice::STATUS_BORRADOR,
            'subtotal' => 1,
            'total' => 1,
            'sent_at' => null,
        ]);

        Artisan::call('billing:generate-draft-invoices');

        $this->assertSame(1, Invoice::query()->count());
        $this->assertDatabaseMissing('invoice_service', ['service_id' => $service->id]);
    }

    public function test_panel_stored_day_overrides_config(): void
    {
        config([
            'automation.draft_generation_enabled' => true,
            'automation.draft_generation_day' => 28,
            'automation.draft_generation_period' => 'current',
        ]);

        AppSetting::setJsonValue(AppSetting::KEY_AUTOMATION_DRAFT_GENERATION_ENABLED, true);
        AppSetting::setJsonValue(AppSetting::KEY_AUTOMATION_DRAFT_GENERATION_DAY, 18);
        AppSetting::setJsonValue(AppSetting::KEY_AUTOMATION_DRAFT_PERIOD, 'current');

        $tz = config('app.timezone');
        Carbon::setTestNow(Carbon::parse('2026-03-18 10:00:00', $tz));

        $company = Company::query()->create([
            'nombre' => 'Empresa Auto',
            'factura_sigla' => 'AUT',
            'nit' => '900111333-1',
            'estado' => Company::ESTADO_ACTIVO,
        ]);
        $empleado = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        Service::query()->create([
            'code' => 'AUTO-SVC-1',
            'company_id' => $company->id,
            'user_id' => $empleado->id,
            'client_name' => 'Cliente',
            'service_type' => 'Obra',
            'description' => 'Servicio',
            'amount' => 50000.00,
            'service_date' => '2026-03-10',
            'status' => Service::STATUS_ACTIVO,
        ]);

        Artisan::call('billing:generate-draft-invoices');

        $this->assertSame(1, Invoice::query()->count());
    }

    public function test_previous_period_uses_prior_month(): void
    {
        config([
            'automation.draft_generation_enabled' => true,
            'automation.draft_generation_day' => 5,
            'automation.draft_generation_period' => 'previous',
        ]);

        $tz = config('app.timezone');
        Carbon::setTestNow(Carbon::parse('2026-03-05 10:00:00', $tz));

        $company = Company::query()->create([
            'nombre' => 'Empresa Auto',
            'factura_sigla' => 'AUT',
            'nit' => '900111333-1',
            'estado' => Company::ESTADO_ACTIVO,
        ]);
        $empleado = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        Service::query()->create([
            'code' => 'AUTO-SVC-FEB',
            'company_id' => $company->id,
            'user_id' => $empleado->id,
            'client_name' => 'Cliente',
            'service_type' => 'Obra',
            'description' => 'Servicio febrero',
            'amount' => 40000.00,
            'service_date' => '2026-02-20',
            'status' => Service::STATUS_ACTIVO,
        ]);

        Artisan::call('billing:generate-draft-invoices');

        $inv = Invoice::query()->first();
        $this->assertNotNull($inv);
        $this->assertSame(2, (int) $inv->period_month);
        $this->assertSame(2026, (int) $inv->period_year);
    }
}
