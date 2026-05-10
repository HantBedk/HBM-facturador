<?php

namespace App\Providers;

use App\MailTransport\Contracts\OutgoingMailSender;
use App\MailTransport\PhpMailerSmtpTransport;
use App\MailTransport\SmtpConnectionVerifier;
use App\Services\PanelNotificationMailSender;
use Illuminate\Support\ServiceProvider;

/**
 * Registra el módulo de envío de correo (implementación concreta vs contrato).
 */
class MailTransportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SmtpConnectionVerifier::class);
        $this->app->singleton(PanelNotificationMailSender::class);
        $this->app->singleton(OutgoingMailSender::class, PhpMailerSmtpTransport::class);
    }
}
