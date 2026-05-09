<?php

namespace Tests\Feature;

use App\Mail\CompanyWelcomeMail;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminCompanyStoreWelcomeMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_welcome_mail_attachment_ready_endpoint(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/companies/welcome-mail-attachment-ready')
            ->assertOk()
            ->assertJsonPath('welcome_pdf_ready', false);
    }

    public function test_store_company_sends_welcome_mail_when_correo_and_pdf_configured(): void
    {
        Mail::fake();
        Storage::fake('local');
        $path = 'mail-company-welcome/w.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 test');
        AppSetting::setJsonValue(AppSetting::KEY_MAIL_COMPANY_WELCOME_PDF, [
            'relative_path' => $path,
            'original_filename' => 'ContratoCliente.pdf',
        ]);

        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/companies', [
            'nombre' => 'Empresa Nueva SA',
            'factura_sigla' => 'ENS',
            'nit' => '900123456-7',
            'correo' => 'contacto@empresa-nueva.test',
        ])->assertCreated();

        Mail::assertSent(CompanyWelcomeMail::class, function (CompanyWelcomeMail $m) {
            return $m->company->nombre === 'Empresa Nueva SA'
                && $m->absolutePdfPath !== null
                && $m->attachmentFilename !== null
                && str_ends_with(strtolower($m->attachmentFilename), '.pdf');
        });
    }

    public function test_store_company_sends_welcome_mail_without_pdf_when_correo_present(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/companies', [
            'nombre' => 'Sin PDF SA',
            'factura_sigla' => 'SPF',
            'correo' => 'a@b.test',
        ])->assertCreated();

        Mail::assertSent(CompanyWelcomeMail::class, function (CompanyWelcomeMail $m) {
            return $m->company->nombre === 'Sin PDF SA'
                && $m->absolutePdfPath === null
                && $m->attachmentFilename === null;
        });
    }

    public function test_store_company_sends_welcome_mail_without_attachment_when_pdf_configured_but_unreadable(): void
    {
        Mail::fake();
        AppSetting::setJsonValue(AppSetting::KEY_MAIL_COMPANY_WELCOME_PDF, [
            'relative_path' => 'mail-company-welcome/fantasma.pdf',
            'original_filename' => 'x.pdf',
        ]);

        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/companies', [
            'nombre' => 'PDF Roto SA',
            'factura_sigla' => 'PRS',
            'correo' => 'roto@b.test',
        ])->assertCreated();

        Mail::assertSent(CompanyWelcomeMail::class, function (CompanyWelcomeMail $m) {
            return $m->company->nombre === 'PDF Roto SA'
                && $m->absolutePdfPath === null
                && $m->attachmentFilename === null;
        });
    }

    public function test_store_company_does_not_send_welcome_mail_without_correo(): void
    {
        Mail::fake();
        Storage::fake('local');
        $path = 'mail-company-welcome/x.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4');
        AppSetting::setJsonValue(AppSetting::KEY_MAIL_COMPANY_WELCOME_PDF, [
            'relative_path' => $path,
            'original_filename' => 'x.pdf',
        ]);

        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/companies', [
            'nombre' => 'Sin Correo SA',
            'factura_sigla' => 'SCS',
        ])->assertCreated();

        Mail::assertNothingSent();
    }
}
