<?php

namespace Tests\Feature;

use App\MailTransport\Contracts\OutgoingMailSender;
use App\MailTransport\MailMessage;
use Tests\TestCase;

class HbmMailTestCommandTest extends TestCase
{
    public function test_hbm_mail_test_command_invokes_outgoing_sender(): void
    {
        $sender = \Mockery::mock(OutgoingMailSender::class);
        $sender->shouldReceive('send')->once()->with(\Mockery::type(MailMessage::class));
        $this->app->instance(OutgoingMailSender::class, $sender);
        config(['mail.from.address' => 'from-cli@test.local', 'mail.from.name' => 'CLI']);

        $this->artisan('hbm:mail-test', ['email' => 'qa-inventario@example.test'])
            ->assertSuccessful()
            ->expectsOutputToContain('qa-inventario@example.test');
    }

    public function test_hbm_mail_test_command_fails_on_invalid_email(): void
    {
        $this->artisan('hbm:mail-test', ['email' => 'no-es-correo'])
            ->assertFailed();
    }
}
