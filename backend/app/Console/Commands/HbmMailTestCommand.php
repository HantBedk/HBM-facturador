<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Verifica configuración SMTP/API de correo (p. ej. Brevo) enviando un mensaje de prueba.
 * Uso en servidor: `php artisan hbm:mail-test operador@suempresa.com`
 */
class HbmMailTestCommand extends Command
{
    protected $signature = 'hbm:mail-test
                            {email? : Correo destino (por defecto MAIL_FROM_ADDRESS)}
                            {--subject= : Asunto personalizado (opcional)}';

    protected $description = 'Envía un correo de prueba con la configuración MAIL_* actual (validar Brevo/SMTP en producción).';

    public function handle(): int
    {
        $to = $this->argument('email') ?: config('mail.from.address');
        if (! is_string($to) || trim($to) === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error('Indique un correo destino válido o defina MAIL_FROM_ADDRESS.');

            return self::FAILURE;
        }

        $subjectOpt = $this->option('subject');
        $subject = (is_string($subjectOpt) && trim($subjectOpt) !== '')
            ? trim($subjectOpt)
            : '['.config('app.name').'] Prueba de correo HBM';
        $body = 'Correo de prueba generado el '.now()->timezone(config('app.timezone'))->toIso8601String()."\n"
            .'Mailer: '.config('mail.default')."\n"
            .'Host: '.(string) config('mail.mailers.'.config('mail.default').'.host', '')."\n";

        try {
            Mail::raw($body, function ($message) use ($to, $subject) {
                $message->to($to)->subject($subject);
            });
        } catch (\Throwable $e) {
            $this->error('Fallo al enviar: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }

        $this->info('Correo de prueba enviado a: '.$to);

        return self::SUCCESS;
    }
}
