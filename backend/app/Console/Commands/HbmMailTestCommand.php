<?php

namespace App\Console\Commands;

use App\Services\PanelNotificationMailSender;
use Illuminate\Console\Command;

/**
 * Envía un correo de prueba por el mismo canal que bienvenida, factura y mantenimiento
 * (PHPMailer + Gmail/SMTP del panel o MAIL_* en .env).
 * Uso: `php artisan hbm:mail-test operador@suempresa.com`
 */
class HbmMailTestCommand extends Command
{
    protected $signature = 'hbm:mail-test
                            {email? : Correo destino (por defecto MAIL_FROM_ADDRESS)}
                            {--subject= : Asunto personalizado (opcional)}';

    protected $description = 'Prueba SMTP: mismo envío que correos transaccionales del panel (no usa MAIL_MAILER=log del framework).';

    public function handle(PanelNotificationMailSender $panelMail): int
    {
        $to = $this->argument('email') ?: config('mail.from.address');
        if (! is_string($to) || trim($to) === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error('Indique un correo destino válido o defina MAIL_FROM_ADDRESS.');

            return self::FAILURE;
        }

        $subjectOpt = $this->option('subject');
        if (is_string($subjectOpt) && trim($subjectOpt) !== '') {
            $subject = trim($subjectOpt);
        } else {
            $subject = 'Prueba de correo (CLI)';
            $app = trim((string) config('app.name', ''));
            if ($app !== '') {
                $subject = '['.$app.'] '.$subject;
            }
        }
        $plain = 'Correo de prueba (artisan hbm:mail-test) generado el '
            .now()->timezone(config('app.timezone'))->toIso8601String()."\n\n"
            .'Este mensaje usa el mismo SMTP que bienvenida de empresa, envío de factura y avisos de mantenimiento.';

        try {
            $panelMail->sendHtml(
                $to,
                $subject,
                '<p>'.nl2br(e($plain), false).'</p>',
                $plain,
            );
        } catch (\Throwable $e) {
            $this->error('Fallo al enviar: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }

        $this->info('Correo de prueba enviado a: '.$to);

        return self::SUCCESS;
    }
}
