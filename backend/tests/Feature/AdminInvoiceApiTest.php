<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminInvoiceApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{company: Company, admin: User, empleado: User, service: Service, invoice: Invoice}
     */
    private function seedInvoiceScenario(): array
    {
        $company = Company::query()->create([
            'nombre' => 'Empresa Test',
            'nit' => '900111222-1',
            'estado' => Company::ESTADO_ACTIVO,
        ]);

        $empleado = User::factory()->create(['rol' => User::ROL_EMPLEADO]);
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);

        $service = Service::query()->create([
            'code' => 'T-SVC-1',
            'company_id' => $company->id,
            'user_id' => $empleado->id,
            'client_name' => 'Cliente',
            'service_type' => 'Mantenimiento',
            'description' => 'Servicio de prueba',
            'amount' => 100000.00,
            'service_date' => '2026-03-15',
            'status' => Service::STATUS_ACTIVO,
        ]);

        $invoice = Invoice::query()->create([
            'code' => 'T-FAC-001',
            'company_id' => $company->id,
            'period_month' => 3,
            'period_year' => 2026,
            'status' => Invoice::STATUS_BORRADOR,
            'subtotal' => 100000.00,
            'total' => 100000.00,
            'sent_at' => null,
        ]);
        $invoice->services()->sync([$service->id]);

        return compact('company', 'admin', 'empleado', 'service', 'invoice');
    }

    public function test_admin_can_show_invoice_including_financial_block(): void
    {
        $s = $this->seedInvoiceScenario();
        Sanctum::actingAs($s['admin']);

        $response = $this->getJson('/api/admin/invoices/'.$s['invoice']->id);

        $response->assertOk()
            ->assertJsonPath('data.code', 'T-FAC-001')
            ->assertJsonPath('data.financial.total_paid', '0')
            ->assertJsonPath('data.financial.balance', '100000.00');
    }

    public function test_admin_can_approve_borrador_from_patch_status(): void
    {
        $s = $this->seedInvoiceScenario();
        Sanctum::actingAs($s['admin']);

        $response = $this->patchJson('/api/admin/invoices/'.$s['invoice']->id.'/status', [
            'status' => Invoice::STATUS_APROBADA,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', Invoice::STATUS_APROBADA);
    }

    public function test_admin_can_register_full_payment_and_invoice_becomes_pagada(): void
    {
        $s = $this->seedInvoiceScenario();
        $s['invoice']->update([
            'status' => Invoice::STATUS_ENVIADA,
            'sent_at' => now(),
        ]);
        Sanctum::actingAs($s['admin']);

        $response = $this->postJson('/api/admin/invoices/'.$s['invoice']->id.'/payments', [
            'amount' => 100000,
            'payment_date' => '2026-03-20',
            'method' => 'Transferencia',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', Invoice::STATUS_PAGADA);
    }

    public function test_empleado_cannot_access_admin_invoice_api(): void
    {
        $s = $this->seedInvoiceScenario();
        Sanctum::actingAs($s['empleado']);

        $this->getJson('/api/admin/invoices/'.$s['invoice']->id)->assertForbidden();
    }
}
