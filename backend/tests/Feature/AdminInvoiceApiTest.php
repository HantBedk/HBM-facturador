<?php

namespace Tests\Feature;

use App\MailTransport\Contracts\OutgoingMailSender;
use App\MailTransport\MailMessage;
use App\Models\AppSetting;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceCatalog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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
            'factura_sigla' => 'TST',
            'nit' => '900111222-1',
            'estado' => Company::ESTADO_ACTIVO,
            'correo' => 'facturas-empresa@test.local',
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
            ->assertJsonPath('data.status', Invoice::STATUS_APROBADA)
            ->assertJsonPath('data.public_access_configured', true);

        $plain = $response->json('public_verification_code');
        $this->assertNotEmpty($plain);
        $this->assertIsString($plain);
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

    public function test_cannot_register_payment_when_invoice_only_aprobada(): void
    {
        $s = $this->seedInvoiceScenario();
        $s['invoice']->update(['status' => Invoice::STATUS_APROBADA]);
        Sanctum::actingAs($s['admin']);

        $response = $this->postJson('/api/admin/invoices/'.$s['invoice']->id.'/payments', [
            'amount' => 50000,
            'payment_date' => '2026-03-20',
            'method' => 'Efectivo',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Marque la factura como enviada antes de registrar pagos.');
    }

    public function test_cannot_overpay_invoice(): void
    {
        $s = $this->seedInvoiceScenario();
        $s['invoice']->update([
            'status' => Invoice::STATUS_ENVIADA,
            'sent_at' => now(),
        ]);
        Sanctum::actingAs($s['admin']);

        $this->postJson('/api/admin/invoices/'.$s['invoice']->id.'/payments', [
            'amount' => 60000,
            'payment_date' => '2026-03-20',
            'method' => 'Transferencia',
        ])->assertOk();

        $r2 = $this->postJson('/api/admin/invoices/'.$s['invoice']->id.'/payments', [
            'amount' => 50000,
            'payment_date' => '2026-03-21',
            'method' => 'Efectivo',
        ]);

        $r2->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_cannot_approve_invoice_without_services(): void
    {
        $s = $this->seedInvoiceScenario();
        $s['invoice']->services()->detach();
        $s['invoice']->update(['subtotal' => '0', 'total' => '0']);
        Sanctum::actingAs($s['admin']);

        $response = $this->patchJson('/api/admin/invoices/'.$s['invoice']->id.'/status', [
            'status' => Invoice::STATUS_APROBADA,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'No se puede aprobar una factura sin servicios.');
    }

    public function test_pdf_borrador_without_preview_returns_422(): void
    {
        $s = $this->seedInvoiceScenario();
        Sanctum::actingAs($s['admin']);

        $this->get('/api/admin/invoices/'.$s['invoice']->id.'/pdf')->assertStatus(422);
    }

    public function test_pdf_borrador_with_preview_returns_pdf(): void
    {
        $s = $this->seedInvoiceScenario();
        Sanctum::actingAs($s['admin']);

        $response = $this->get('/api/admin/invoices/'.$s['invoice']->id.'/pdf?preview=1');

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
    }

    public function test_pdf_aprobada_official_without_preview_returns_pdf(): void
    {
        $s = $this->seedInvoiceScenario();
        $s['invoice']->update(['status' => Invoice::STATUS_APROBADA]);
        Sanctum::actingAs($s['admin']);

        $response = $this->get('/api/admin/invoices/'.$s['invoice']->id.'/pdf');

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
    }

    public function test_export_invoices_csv_ok(): void
    {
        $s = $this->seedInvoiceScenario();
        Sanctum::actingAs($s['admin']);

        $this->get('/api/admin/export/invoices')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_empleado_cannot_access_admin_invoice_api(): void
    {
        $s = $this->seedInvoiceScenario();
        Sanctum::actingAs($s['empleado']);

        $this->getJson('/api/admin/invoices/'.$s['invoice']->id)->assertForbidden();
    }

    public function test_admin_can_delete_borrador_invoice(): void
    {
        $s = $this->seedInvoiceScenario();
        Sanctum::actingAs($s['admin']);

        $this->deleteJson('/api/admin/invoices/'.$s['invoice']->id)
            ->assertOk()
            ->assertJsonPath('message', 'Factura eliminada.');

        $this->assertDatabaseMissing('invoices', ['id' => $s['invoice']->id]);
    }

    public function test_admin_can_delete_aprobada_invoice_without_payments(): void
    {
        $s = $this->seedInvoiceScenario();
        $s['invoice']->update(['status' => Invoice::STATUS_APROBADA]);
        Sanctum::actingAs($s['admin']);

        $this->deleteJson('/api/admin/invoices/'.$s['invoice']->id)->assertOk();

        $this->assertDatabaseMissing('invoices', ['id' => $s['invoice']->id]);
    }

    public function test_cannot_delete_enviada_invoice(): void
    {
        $s = $this->seedInvoiceScenario();
        $s['invoice']->update([
            'status' => Invoice::STATUS_ENVIADA,
            'sent_at' => now(),
        ]);
        Sanctum::actingAs($s['admin']);

        $this->deleteJson('/api/admin/invoices/'.$s['invoice']->id)
            ->assertStatus(422);
    }

    public function test_store_invoice_generates_fac_code_one_per_company_per_day(): void
    {
        $tz = config('app.timezone');
        $company = Company::query()->create([
            'nombre' => 'Empresa Fact Test',
            'factura_sigla' => 'TST',
            'nit' => '900199988-7',
            'estado' => Company::ESTADO_ACTIVO,
        ]);
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $empleado = User::factory()->create(['rol' => User::ROL_EMPLEADO]);

        $makeService = fn (string $code, string $serviceDate) => Service::query()->create([
            'code' => $code,
            'company_id' => $company->id,
            'user_id' => $empleado->id,
            'client_name' => 'Cliente',
            'service_type' => 'Mantenimiento',
            'description' => 'Descripción larga del trabajo realizado.',
            'amount' => 50000.00,
            'service_date' => $serviceDate,
            'status' => Service::STATUS_ACTIVO,
        ]);

        Sanctum::actingAs($admin);

        Carbon::setTestNow(Carbon::parse('2026-04-03 10:00:00', $tz));
        $s1 = $makeService('T-FAC-SVC-A', '2026-04-12');
        $r1 = $this->postJson('/api/admin/invoices', [
            'company_id' => $company->id,
            'period_year' => 2026,
            'period_month' => 4,
            'service_ids' => [$s1->id],
        ]);
        $r1->assertCreated()->assertJsonPath('data.code', 'FAC-260403-TST');

        Carbon::setTestNow(Carbon::parse('2026-04-03 11:00:00', $tz));
        $s2 = $makeService('T-FAC-SVC-B', '2026-04-20');
        $this->postJson('/api/admin/invoices', [
            'company_id' => $company->id,
            'period_year' => 2026,
            'period_month' => 4,
            'service_ids' => [$s2->id],
        ])->assertStatus(422)->assertJsonValidationErrors('company_id');

        Carbon::setTestNow(Carbon::parse('2026-05-08 09:00:00', $tz));
        $s3 = $makeService('T-FAC-SVC-C', '2026-05-08');
        $r3 = $this->postJson('/api/admin/invoices', [
            'company_id' => $company->id,
            'period_year' => 2026,
            'period_month' => 5,
            'service_ids' => [$s3->id],
        ]);
        $r3->assertCreated()->assertJsonPath('data.code', 'FAC-260508-TST');

        Carbon::setTestNow();
    }

    public function test_store_invoice_applies_catalog_iva_to_total(): void
    {
        $company = Company::query()->create([
            'nombre' => 'Empresa IVA',
            'factura_sigla' => 'IVA',
            'nit' => '900177766-1',
            'estado' => Company::ESTADO_ACTIVO,
        ]);

        $cat = ServiceCatalog::query()->create([
            'name' => 'Categoría con IVA test',
            'description' => '—',
            'base_price' => 100000,
            'iva_percent' => 19,
            'status' => ServiceCatalog::STATUS_ACTIVO,
        ]);

        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        $empleado = User::factory()->create(['rol' => User::ROL_EMPLEADO]);

        $service = Service::query()->create([
            'code' => 'S-IVA-1',
            'company_id' => $company->id,
            'user_id' => $empleado->id,
            'catalog_id' => $cat->id,
            'client_name' => 'Cliente',
            'service_type' => 'Servicio',
            'description' => 'Trabajo',
            'amount' => 100000.00,
            'service_date' => '2026-06-15',
            'status' => Service::STATUS_ACTIVO,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/invoices', [
            'company_id' => $company->id,
            'period_year' => 2026,
            'period_month' => 6,
            'service_ids' => [$service->id],
        ])
            ->assertCreated()
            ->assertJsonPath('data.subtotal', '100000.00')
            ->assertJsonPath('data.total', '119000.00')
            ->assertJsonPath('data.iva_amount', '19000.00');
    }

    public function test_send_invoice_email_rejects_borrador(): void
    {
        $sender = \Mockery::mock(OutgoingMailSender::class);
        $sender->shouldReceive('send')->never();
        $this->app->instance(OutgoingMailSender::class, $sender);

        $s = $this->seedInvoiceScenario();
        Sanctum::actingAs($s['admin']);

        $this->postJson('/api/admin/invoices/'.$s['invoice']->id.'/send-email')->assertStatus(422);
    }

    public function test_send_invoice_email_requires_valid_company_correo(): void
    {
        $sender = \Mockery::mock(OutgoingMailSender::class);
        $sender->shouldReceive('send')->never();
        $this->app->instance(OutgoingMailSender::class, $sender);

        $s = $this->seedInvoiceScenario();
        $s['company']->update(['correo' => '   ']);
        $s['invoice']->update(['status' => Invoice::STATUS_APROBADA]);
        Sanctum::actingAs($s['admin']);

        $this->postJson('/api/admin/invoices/'.$s['invoice']->id.'/send-email')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['company']);
    }

    public function test_send_invoice_email_dispatches_pdf_mail(): void
    {
        $sender = \Mockery::mock(OutgoingMailSender::class);
        $sender->shouldReceive('send')->once()->with(\Mockery::on(function (MailMessage $m): bool {
            return $m->toEmail === 'facturas-empresa@test.local'
                && str_contains($m->subject, 'T-FAC-001')
                && str_contains($m->htmlBody, 'consulta-factura')
                && $m->blobAttachments !== null
                && count($m->blobAttachments) >= 1;
        }));
        $this->app->instance(OutgoingMailSender::class, $sender);

        $s = $this->seedInvoiceScenario();
        $s['invoice']->update(['status' => Invoice::STATUS_APROBADA]);
        Sanctum::actingAs($s['admin']);

        $this->postJson('/api/admin/invoices/'.$s['invoice']->id.'/send-email')
            ->assertOk()
            ->assertJsonPath('sent_to', 'facturas-empresa@test.local');
    }

    public function test_send_invoice_email_uses_custom_message_template(): void
    {
        AppSetting::setJsonValue(AppSetting::KEY_MAIL_NOTIFICATION_TEMPLATES, [
            'welcome_subject' => '',
            'welcome_body' => '',
            'invoice_to_company_subject' => 'DOC {{codigo_factura}}',
            'invoice_to_company_body' => 'Empresa={{nombre_empresa}} Codigo={{codigo_factura}}',
        ]);

        $sender = \Mockery::mock(OutgoingMailSender::class);
        $sender->shouldReceive('send')->once()->with(\Mockery::on(function (MailMessage $m): bool {
            return $m->subject === 'DOC T-FAC-001'
                && str_contains($m->htmlBody, 'Empresa=Empresa Test')
                && str_contains($m->htmlBody, 'Codigo=T-FAC-001');
        }));
        $this->app->instance(OutgoingMailSender::class, $sender);

        $s = $this->seedInvoiceScenario();
        $s['invoice']->update(['status' => Invoice::STATUS_APROBADA]);
        Sanctum::actingAs($s['admin']);

        $this->postJson('/api/admin/invoices/'.$s['invoice']->id.'/send-email')->assertOk();
    }

    public function test_send_invoice_email_attaches_supplement_pdf_when_configured(): void
    {
        Storage::fake('local');
        $supPath = 'mail-invoice-supplement/extra.pdf';
        Storage::disk('local')->put($supPath, '%PDF-1.4 extra');
        AppSetting::setJsonValue(AppSetting::KEY_MAIL_INVOICE_SUPPLEMENT_PDF, [
            'relative_path' => $supPath,
            'original_filename' => 'Anexo.pdf',
        ]);

        $sender = \Mockery::mock(OutgoingMailSender::class);
        $sender->shouldReceive('send')->once()->with(\Mockery::on(function (MailMessage $m): bool {
            $files = count($m->fileAttachments ?? []);
            $blobs = count($m->blobAttachments ?? []);

            return $files === 1 && $blobs === 1;
        }));
        $this->app->instance(OutgoingMailSender::class, $sender);

        $s = $this->seedInvoiceScenario();
        $s['invoice']->update(['status' => Invoice::STATUS_APROBADA]);
        Sanctum::actingAs($s['admin']);

        $this->postJson('/api/admin/invoices/'.$s['invoice']->id.'/send-email')->assertOk();
    }
}
