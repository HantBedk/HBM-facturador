<?php

namespace Tests\Feature;

use App\MailTransport\Contracts\OutgoingMailSender;
use App\MailTransport\MailMessage;
use App\Models\AppSetting;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $sender = \Mockery::mock(OutgoingMailSender::class);
        $sender->shouldReceive('send')->once()->with(\Mockery::on(function (MailMessage $m): bool {
            return $m->toEmail === 'contacto@empresa-nueva.test'
                && str_contains($m->subject, 'Empresa Nueva SA')
                && $m->fileAttachments !== null
                && count($m->fileAttachments) === 1
                && str_ends_with(strtolower($m->fileAttachments[0]['name']), '.pdf')
                && is_readable($m->fileAttachments[0]['path']);
        }));
        $this->app->instance(OutgoingMailSender::class, $sender);

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
        ])->assertCreated()
            ->assertJsonPath('welcome_mail.sent', true)
            ->assertJsonPath('welcome_mail.to', 'contacto@empresa-nueva.test');
    }

    public function test_store_company_sends_welcome_mail_without_pdf_when_correo_present(): void
    {
        $sender = \Mockery::mock(OutgoingMailSender::class);
        $sender->shouldReceive('send')->once()->with(\Mockery::on(function (MailMessage $m): bool {
            return $m->toEmail === 'a@b.test'
                && str_contains($m->subject, 'Sin PDF SA')
                && $m->fileAttachments === null
                && $m->blobAttachments === null;
        }));
        $this->app->instance(OutgoingMailSender::class, $sender);

        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/companies', [
            'nombre' => 'Sin PDF SA',
            'factura_sigla' => 'SPF',
            'correo' => 'a@b.test',
        ])->assertCreated()
            ->assertJsonPath('welcome_mail.sent', true);
    }

    public function test_store_company_sends_welcome_mail_without_attachment_when_pdf_configured_but_unreadable(): void
    {
        $sender = \Mockery::mock(OutgoingMailSender::class);
        $sender->shouldReceive('send')->once()->with(\Mockery::on(function (MailMessage $m): bool {
            return $m->toEmail === 'roto@b.test'
                && $m->fileAttachments === null;
        }));
        $this->app->instance(OutgoingMailSender::class, $sender);

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
        ])->assertCreated()
            ->assertJsonPath('welcome_mail.sent', true);
    }

    public function test_store_company_does_not_send_welcome_mail_without_correo(): void
    {
        $sender = \Mockery::mock(OutgoingMailSender::class);
        $sender->shouldReceive('send')->never();
        $this->app->instance(OutgoingMailSender::class, $sender);

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
        ])->assertCreated()
            ->assertJsonPath('welcome_mail.sent', false)
            ->assertJsonPath('welcome_mail.skipped_reason', 'no_correo');
    }

    public function test_update_company_adding_correo_sends_welcome(): void
    {
        $sender = \Mockery::mock(OutgoingMailSender::class);
        $sender->shouldReceive('send')->once()->with(\Mockery::on(function (MailMessage $m): bool {
            return $m->toEmail === 'nuevo@empresa.test'
                && str_contains($m->subject, 'Sin Mail SA');
        }));
        $this->app->instance(OutgoingMailSender::class, $sender);

        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/companies', [
            'nombre' => 'Sin Mail SA',
            'factura_sigla' => 'SMS',
            'nit' => '901-SMS',
            'estado' => 'activo',
        ])->assertCreated()
            ->assertJsonPath('welcome_mail.skipped_reason', 'no_correo');

        $id = (int) Company::query()->where('factura_sigla', 'SMS')->value('id');
        $this->assertGreaterThan(0, $id);

        $this->putJson('/api/admin/companies/'.$id, [
            'nombre' => 'Sin Mail SA',
            'factura_sigla' => 'SMS',
            'nit' => '901-SMS',
            'correo' => 'nuevo@empresa.test',
            'estado' => 'activo',
        ])->assertOk()
            ->assertJsonPath('welcome_mail.sent', true)
            ->assertJsonPath('welcome_mail.to', 'nuevo@empresa.test');
    }
}
