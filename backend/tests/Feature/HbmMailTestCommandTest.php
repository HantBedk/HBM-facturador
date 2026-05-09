<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class HbmMailTestCommandTest extends TestCase
{
    public function test_hbm_mail_test_command_succeeds_with_array_mailer(): void
    {
        Mail::fake();
        config(['mail.default' => 'array']);

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
