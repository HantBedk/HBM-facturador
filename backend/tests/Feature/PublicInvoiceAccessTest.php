<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Services\InvoicePublicAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicInvoiceAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{invoice: Invoice, plain: string}
     */
    private function seedPublicInvoice(): array
    {
        $company = Company::query()->create([
            'nombre' => 'Cliente Público',
            'factura_sigla' => 'CLP',
            'nit' => '900111222-9',
            'estado' => Company::ESTADO_ACTIVO,
        ]);

        $empleado = User::factory()->create(['rol' => User::ROL_EMPLEADO]);

        $service = Service::query()->create([
            'code' => 'PUB-SVC-1',
            'company_id' => $company->id,
            'user_id' => $empleado->id,
            'client_name' => 'Cliente',
            'service_type' => 'Mantenimiento',
            'description' => 'Servicio de prueba para consulta pública',
            'amount' => 100000.00,
            'service_date' => '2026-03-15',
            'status' => Service::STATUS_ACTIVO,
        ]);

        $invoice = Invoice::query()->create([
            'code' => 'PUB-FAC-001',
            'company_id' => $company->id,
            'period_month' => 3,
            'period_year' => 2026,
            'status' => Invoice::STATUS_ENVIADA,
            'subtotal' => 100000.00,
            'total' => 100000.00,
            'sent_at' => now(),
        ]);
        $invoice->services()->sync([$service->id]);

        $plain = 'TOKEN-TEST-PUBLIC-001';
        app(InvoicePublicAccessService::class)->setPlainToken($invoice, $plain);

        return ['invoice' => $invoice->fresh(), 'plain' => $plain];
    }

    public function test_public_consult_requires_query(): void
    {
        $this->seedPublicInvoice();

        $this->postJson('/api/public/invoices/consult', [])->assertStatus(422);
    }

    public function test_public_consult_ok_with_invoice_code_only(): void
    {
        $this->seedPublicInvoice();

        $this->postJson('/api/public/invoices/consult', [
            'query' => 'PUB-FAC-001',
        ])->assertOk()
            ->assertJsonPath('kind', 'invoice')
            ->assertJsonPath('invoice.code', 'PUB-FAC-001');
    }

    public function test_public_consult_invoice_code_case_insensitive(): void
    {
        $this->seedPublicInvoice();

        $this->postJson('/api/public/invoices/consult', [
            'query' => 'pub-fac-001',
        ])->assertOk()
            ->assertJsonPath('invoice.code', 'PUB-FAC-001');
    }

    public function test_public_consult_by_nit_returns_invoice_list(): void
    {
        $this->seedPublicInvoice();

        $this->postJson('/api/public/invoices/consult', [
            'query' => '9001112229',
        ])->assertOk()
            ->assertJsonPath('kind', 'invoice_list')
            ->assertJsonPath('company.nit', '900111222-9')
            ->assertJsonCount(1, 'invoices')
            ->assertJsonPath('invoices.0.code', 'PUB-FAC-001');
    }

    public function test_public_consult_unknown_query_returns_404(): void
    {
        $this->seedPublicInvoice();

        $this->postJson('/api/public/invoices/consult', [
            'query' => 'NO-EXISTE-999',
        ])->assertStatus(404)
            ->assertJsonPath('code', 'public_invoice_denied');
    }

    public function test_public_consult_rejects_borrador(): void
    {
        $s = $this->seedPublicInvoice();
        $s['invoice']->update(['status' => Invoice::STATUS_BORRADOR]);

        $this->postJson('/api/public/invoices/consult', [
            'query' => 'PUB-FAC-001',
        ])->assertStatus(403)
            ->assertJsonPath('code', 'invoice_unavailable')
            ->assertJsonPath('reason', 'borrador');
    }

    public function test_public_consult_normalizes_spaces_in_invoice_code(): void
    {
        $this->seedPublicInvoice();

        $this->postJson('/api/public/invoices/consult', [
            'query' => '  PUB - FAC - 001 ',
        ])->assertOk()
            ->assertJsonPath('kind', 'invoice')
            ->assertJsonPath('invoice.code', 'PUB-FAC-001');
    }
}
