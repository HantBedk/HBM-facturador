<?php

namespace Tests\Feature;

use App\Jobs\SendCompanyWelcomeMailJob;
use App\MailTransport\Contracts\OutgoingMailSender;
use App\MailTransport\MailMessage;
use App\Models\AppSetting;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyWelcomeMailSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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

    public function test_store_company_queues_welcome_mail_when_correo_and_pdf_configured(): void
    {
        Bus::fake();

        Storage::fake('local');
        $path = 'mail-company-welcome/w.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 test');
        AppSetting::setJsonValue(AppSetting::KEY_MAIL_COMPANY_WELCOME_PDF, [
            'relative_path' => $path,
            'original_filename' => 'ContratoCliente.pdf',
        ]);

        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/companies', [
            'nombre' => 'Empresa Nueva SA',
            'factura_sigla' => 'ENS',
            'nit' => '900123456-7',
            'direccion' => 'Carrera 1 # 2-3, Bogotá',
            'correo' => 'contacto@empresa-nueva.test',
        ])->assertCreated();

        $companyId = (int) $response->json('data.id');
        $this->assertGreaterThan(0, $companyId);

        $response->assertJsonPath('welcome_mail.queued', true)
            ->assertJsonPath('welcome_mail.sent', false)
            ->assertJsonPath('welcome_mail.to', 'contacto@empresa-nueva.test');

        Bus::assertDispatched(SendCompanyWelcomeMailJob::class, function (SendCompanyWelcomeMailJob $job) use ($companyId, $admin): bool {
            return $job->companyId === $companyId && $job->actorUserId === $admin->id;
        });
    }

    public function test_store_company_queues_welcome_mail_without_pdf_when_correo_present(): void
    {
        Bus::fake();

        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/companies', [
            'nombre' => 'Sin PDF SA',
            'factura_sigla' => 'SPF',
            'direccion' => 'Calle 10 # 20-30',
            'correo' => 'a@b.test',
        ])->assertCreated();

        $companyId = (int) $response->json('data.id');
        $response->assertJsonPath('welcome_mail.queued', true)
            ->assertJsonPath('welcome_mail.sent', false);

        Bus::assertDispatched(SendCompanyWelcomeMailJob::class, function (SendCompanyWelcomeMailJob $job) use ($companyId, $admin): bool {
            return $job->companyId === $companyId && $job->actorUserId === $admin->id;
        });
    }

    public function test_welcome_mail_job_sends_with_pdf_attachment_when_configured(): void
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
        $company = Company::query()->create([
            'nombre' => 'Empresa Nueva SA',
            'factura_sigla' => 'ENS',
            'nit' => '900123456-7',
            'correo' => 'contacto@empresa-nueva.test',
            'estado' => Company::ESTADO_ACTIVO,
        ]);

        (new SendCompanyWelcomeMailJob($company->id, $admin->id))
            ->handle(app(CompanyWelcomeMailSender::class));
    }

    public function test_welcome_mail_job_sends_without_attachment_when_pdf_configured_but_unreadable(): void
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
        $company = Company::query()->create([
            'nombre' => 'PDF Roto SA',
            'factura_sigla' => 'PRS',
            'correo' => 'roto@b.test',
            'estado' => Company::ESTADO_ACTIVO,
        ]);

        (new SendCompanyWelcomeMailJob($company->id, $admin->id))
            ->handle(app(CompanyWelcomeMailSender::class));
    }

    public function test_store_company_does_not_queue_welcome_mail_without_correo(): void
    {
        Bus::fake();

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
            'direccion' => 'Av. Principal 100',
        ])->assertCreated()
            ->assertJsonPath('welcome_mail.queued', false)
            ->assertJsonPath('welcome_mail.sent', false)
            ->assertJsonPath('welcome_mail.skipped_reason', 'no_correo');

        Bus::assertNothingDispatched();
    }

    public function test_update_company_adding_correo_queues_welcome_mail(): void
    {
        Bus::fake();

        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/companies', [
            'nombre' => 'Sin Mail SA',
            'factura_sigla' => 'SMS',
            'nit' => '901-SMS',
            'direccion' => 'Calle 5 # 6-7',
            'estado' => 'activo',
        ])->assertCreated()
            ->assertJsonPath('welcome_mail.skipped_reason', 'no_correo');

        Bus::assertNothingDispatched();

        $id = (int) Company::query()->where('factura_sigla', 'SMS')->value('id');
        $this->assertGreaterThan(0, $id);

        $this->putJson('/api/admin/companies/'.$id, [
            'nombre' => 'Sin Mail SA',
            'factura_sigla' => 'SMS',
            'nit' => '901-SMS',
            'direccion' => 'Calle 5 # 6-7',
            'correo' => 'nuevo@empresa.test',
            'estado' => 'activo',
        ])->assertOk()
            ->assertJsonPath('welcome_mail.queued', true)
            ->assertJsonPath('welcome_mail.sent', false)
            ->assertJsonPath('welcome_mail.to', 'nuevo@empresa.test');

        Bus::assertDispatched(SendCompanyWelcomeMailJob::class, function (SendCompanyWelcomeMailJob $job) use ($id, $admin): bool {
            return $job->companyId === $id && $job->actorUserId === $admin->id;
        });
    }
}
