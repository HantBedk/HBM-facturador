<?php

namespace Tests\Feature;

use App\MailTransport\Contracts\OutgoingMailSender;
use App\MailTransport\MailMessage;
use App\MailTransport\SmtpConnectionVerifier;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPMailer\PHPMailer\Exception as PhpMailerException;
use Tests\TestCase;

class AdminMailNotificationsSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function unlockMailNotificationsFor(User $admin): void
    {
        $this->postJson('/api/admin/settings/mail-notifications/unlock', [
            'current_password' => 'password',
        ])->assertOk()
            ->assertJsonPath('unlocked', true);
    }

    public function test_outbound_status_accessible_without_unlock(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/settings/mail-notifications/outbound-status')
            ->assertOk()
            ->assertJsonStructure(['outbound_configured']);
    }

    public function test_unlock_status_false_until_unlock(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/settings/mail-notifications/unlock-status')
            ->assertOk()
            ->assertJsonPath('unlocked', false);

        $this->unlockMailNotificationsFor($admin);

        $this->getJson('/api/admin/settings/mail-notifications/unlock-status')
            ->assertOk()
            ->assertJsonPath('unlocked', true);
    }

    public function test_mail_notifications_show_forbidden_without_unlock(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/settings/mail-notifications')
            ->assertForbidden()
            ->assertJsonPath('code', 'mail_config_locked');
    }

    public function test_unlock_rejects_wrong_password(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/settings/mail-notifications/unlock', [
            'current_password' => 'wrong-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);
    }

    public function test_admin_can_read_mail_notifications_settings(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $this->unlockMailNotificationsFor($admin);

        $this->getJson('/api/admin/settings/mail-notifications')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'from_address',
                    'from_name',
                    'effective_from_address',
                    'effective_from_name',
                    'company_welcome_pdf_configured',
                    'company_welcome_pdf_filename',
                    'welcome_subject',
                    'welcome_body',
                    'invoice_to_company_subject',
                    'invoice_to_company_body',
                    'maintenance_subject',
                    'maintenance_body',
                    'invoice_supplement_pdf_configured',
                    'maintenance_supplement_pdf_configured',
                    'smtp' => [
                        'host',
                        'port',
                        'encryption',
                        'username',
                        'has_smtp_password',
                        'panel_smtp_ready',
                    ],
                ],
                'help_gmail_smtp',
                'help_company_welcome_pdf',
                'help_invoice_supplement_pdf',
                'help_maintenance_supplement_pdf',
                'help_mail_templates',
            ])
            ->assertJsonPath('data.company_welcome_pdf_configured', false)
            ->assertJsonPath('data.company_welcome_pdf_filename', null)
            ->assertJsonPath('data.invoice_supplement_pdf_configured', false)
            ->assertJsonPath('data.maintenance_supplement_pdf_configured', false);
    }

    public function test_admin_can_update_mail_settings(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $this->unlockMailNotificationsFor($admin);

        $this->putJson('/api/admin/settings/mail-notifications', [
            'welcome_subject' => 'Hola {{nombre_empresa}}',
            'welcome_body' => "Gracias por unirse, {{nombre_empresa}}.\nSaludos.",
            'invoice_to_company_subject' => 'Su factura {{codigo_factura}}',
            'invoice_to_company_body' => "Estimado cliente {{nombre_empresa}}:\nFactura {{codigo_factura}} adjunta.",
        ])->assertOk();

        $this->assertDatabaseMissing('app_settings', ['key' => AppSetting::KEY_MAIL_NOTIFICATIONS_FROM]);

        $tplRow = AppSetting::query()->where('key', AppSetting::KEY_MAIL_NOTIFICATION_TEMPLATES)->first();
        $this->assertIsArray($tplRow->value);
        $this->assertStringContainsString('{{nombre_empresa}}', (string) $tplRow->value['welcome_subject']);
        $this->assertStringContainsString('{{codigo_factura}}', (string) $tplRow->value['invoice_to_company_body']);
    }

    public function test_save_smtp_does_not_persist_when_verifier_fails(): void
    {
        config(['mail.verify_smtp_on_save' => true]);
        $verifier = \Mockery::mock(SmtpConnectionVerifier::class);
        $verifier->shouldReceive('verify')
            ->once()
            ->andThrow(new PhpMailerException('SMTP connect() failed'));
        $this->app->instance(SmtpConnectionVerifier::class, $verifier);

        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);
        $this->unlockMailNotificationsFor($admin);

        $this->putJson('/api/admin/settings/mail-notifications', [
            'smtp_host' => 'smtp.gmail.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'cuenta@gmail.com',
            'smtp_password' => 'bad-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['smtp_password']);

        $this->assertDatabaseMissing('app_settings', ['key' => AppSetting::KEY_MAIL_RUNTIME_TRANSPORT]);
    }

    public function test_save_smtp_rejects_when_organization_missing_mail_fields(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);
        $this->unlockMailNotificationsFor($admin);

        $verifier = \Mockery::mock(SmtpConnectionVerifier::class);
        $verifier->shouldReceive('verify')->once()->andReturn(true);
        $this->app->instance(SmtpConnectionVerifier::class, $verifier);

        $this->putJson('/api/admin/settings/mail-notifications', [
            'smtp_host' => 'smtp.gmail.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'cuenta@gmail.com',
            'smtp_password' => 'app-password-16chars',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['smtp_username']);
    }

    public function test_admin_can_save_gmail_smtp_from_panel(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);
        $this->unlockMailNotificationsFor($admin);

        app(\App\Services\SystemOrganizationProfileService::class)->persist([
            'trade_name' => 'Mi Empresa',
            'email' => 'facturacion@miempresa.test',
        ]);

        $verifier = \Mockery::mock(SmtpConnectionVerifier::class);
        $verifier->shouldReceive('verify')->once()->andReturn(true);
        $this->app->instance(SmtpConnectionVerifier::class, $verifier);

        $this->putJson('/api/admin/settings/mail-notifications', [
            'smtp_host' => 'smtp.gmail.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'cuenta@gmail.com',
            'smtp_password' => 'app-password-16chars',
        ])->assertOk()
            ->assertJsonPath('data.smtp.host', 'smtp.gmail.com')
            ->assertJsonPath('data.smtp.username', 'cuenta@gmail.com')
            ->assertJsonPath('data.smtp.panel_smtp_ready', true)
            ->assertJsonPath('data.effective_from_address', 'facturacion@miempresa.test')
            ->assertJsonPath('data.effective_from_name', 'Mi Empresa');

        $smtpRow = AppSetting::query()->where('key', AppSetting::KEY_MAIL_RUNTIME_TRANSPORT)->first();
        $this->assertIsArray($smtpRow->value);
        $this->assertSame('smtp.gmail.com', $smtpRow->value['host']);
        $this->assertNotSame('app-password-16chars', $smtpRow->value['password_encrypted']);

        $fromRow = AppSetting::query()->where('key', AppSetting::KEY_MAIL_NOTIFICATIONS_FROM)->first();
        $this->assertIsArray($fromRow->value);
        $this->assertSame('facturacion@miempresa.test', $fromRow->value['address']);
        $this->assertSame('Mi Empresa', $fromRow->value['name']);
    }

    public function test_admin_can_upload_welcome_template_pdf(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $this->unlockMailNotificationsFor($admin);

        $file = UploadedFile::fake()->create('MiContrato.pdf', 400, 'application/pdf');

        $this->post('/api/admin/settings/mail-notifications/template-pdf', [
            'kind' => 'welcome',
            'file' => $file,
        ])->assertOk()
            ->assertJsonPath('data.company_welcome_pdf_configured', true)
            ->assertJsonPath('data.company_welcome_pdf_filename', 'MiContrato.pdf');

        $row = AppSetting::query()->where('key', AppSetting::KEY_MAIL_COMPANY_WELCOME_PDF)->first();
        $this->assertIsArray($row->value);
        $this->assertArrayHasKey('relative_path', $row->value);
        Storage::disk('local')->assertExists($row->value['relative_path']);
    }

    public function test_admin_can_delete_welcome_template_pdf(): void
    {
        Storage::fake('local');
        $path = 'mail-company-welcome/test.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 test');
        AppSetting::setJsonValue(AppSetting::KEY_MAIL_COMPANY_WELCOME_PDF, [
            'relative_path' => $path,
            'original_filename' => 'Doc.pdf',
        ]);

        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $this->unlockMailNotificationsFor($admin);

        $this->deleteJson('/api/admin/settings/mail-notifications/template-pdf', [
            'kind' => 'welcome',
        ])->assertOk()
            ->assertJsonPath('data.company_welcome_pdf_configured', false);

        $this->assertDatabaseMissing('app_settings', ['key' => AppSetting::KEY_MAIL_COMPANY_WELCOME_PDF]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_test_send_mail_forbidden_without_unlock(): void
    {
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/settings/mail-notifications/test-send', [
            'to' => 'a@b.test',
        ])->assertForbidden()
            ->assertJsonPath('code', 'mail_config_locked');
    }

    public function test_test_send_mail_sends_mailable_when_unlocked(): void
    {
        $sender = \Mockery::mock(OutgoingMailSender::class);
        $sender->shouldReceive('send')
            ->once()
            ->withArgs(function (MailMessage $m): bool {
                return $m->toEmail === 'dest@example.test'
                    && $m->fromEmail === 'from@example.test'
                    && $m->fromName === 'Remitente Prueba'
                    && $m->subject === 'Prueba HBM'
                    && $m->htmlBody === '<p>Hola</p>';
            });
        $this->app->instance(OutgoingMailSender::class, $sender);

        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);
        $this->unlockMailNotificationsFor($admin);
        AppSetting::setJsonValue(AppSetting::KEY_MAIL_NOTIFICATIONS_FROM, [
            'address' => 'from@example.test',
            'name' => 'Remitente Prueba',
        ]);

        $this->postJson('/api/admin/settings/mail-notifications/test-send', [
            'to' => 'dest@example.test',
        ])->assertOk()
            ->assertJsonPath('sent_to', 'dest@example.test');
    }

    public function test_test_send_mail_rejects_when_no_effective_from(): void
    {
        config(['mail.from.address' => '', 'mail.from.name' => '']);
        $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);
        Sanctum::actingAs($admin);
        $this->unlockMailNotificationsFor($admin);

        $this->postJson('/api/admin/settings/mail-notifications/test-send', [
            'to' => 'dest@example.test',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['to']);
    }
}
