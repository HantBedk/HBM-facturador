<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Crypt;

/**
 * SMTP guardado en panel (p. ej. Gmail: smtp.gmail.com, app password cifrada).
 * Si no hay host en BD, PhpMailerSmtpTransport usa MAIL_* del .env.
 */
class MailRuntimeSettingsService
{
    /**
     * @return array<string, mixed>
     */
    public function publicConfig(): array
    {
        $cfg = $this->rawConfig();
        $host = trim((string) ($cfg['host'] ?? ''));

        return [
            'host' => $host,
            'port' => (int) ($cfg['port'] ?? 587),
            'encryption' => (string) ($cfg['encryption'] ?? 'tls'),
            'username' => (string) ($cfg['username'] ?? ''),
            'has_smtp_password' => $this->decryptValue($cfg['password_encrypted'] ?? null) !== null,
            'panel_smtp_ready' => $host !== '' && $this->decryptValue($cfg['password_encrypted'] ?? null) !== null,
        ];
    }

    /**
     * Credenciales SMTP para enviar correo: panel (host en BD) con contraseña cifrada y, si no hay,
     * contraseña de MAIL_PASSWORD en .env con el mismo host/usuario del panel.
     * Si no hay host en panel, usa solo MAIL_* del .env.
     *
     * @return array{host: string, port: int, username: string, password: string, useImplicitTls: bool}
     */
    public function resolveSmtpForSend(): array
    {
        $cfg = $this->rawConfig();
        $panelHost = trim((string) ($cfg['host'] ?? ''));

        if ($panelHost === '') {
            return $this->smtpCredentialsFromEnvOrThrow();
        }

        $username = trim((string) ($cfg['username'] ?? ''));
        if ($username === '') {
            throw new \InvalidArgumentException(
                'Hay servidor SMTP guardado en el panel pero falta el usuario (correo Gmail completo). Abra Admin → Correo del sistema y complételo.'
            );
        }

        $blob = $cfg['password_encrypted'] ?? null;
        $password = $this->decryptValue($blob);

        if ($password !== null && trim($password) !== '') {
            // contraseña del panel
        } elseif (is_string($blob) && trim($blob) !== '') {
            throw new \InvalidArgumentException(
                'La contraseña SMTP del panel no se puede descifrar (p. ej. cambió APP_KEY en el servidor). Abra Admin → Correo del sistema, «Reconfigurar cuenta», y vuelva a guardar la contraseña de aplicación de Google.'
            );
        } else {
            $envPassword = config('mail.mailers.smtp.password');
            $envPlain = $envPassword !== null ? trim((string) $envPassword) : '';
            if ($envPlain === '') {
                throw new \InvalidArgumentException(
                    'Hay servidor Gmail en el panel pero no hay contraseña guardada ni MAIL_PASSWORD en .env. Abra Admin → Correo del sistema, desbloquee, «Reconfigurar cuenta» y guarde la contraseña de aplicación; o defina MAIL_PASSWORD en el servidor y vuelva a intentar.'
                );
            }
            $password = $envPlain;
        }

        $port = (int) ($cfg['port'] ?? 587);
        if ($port <= 0) {
            $port = 587;
        }
        $encryption = strtolower(trim((string) ($cfg['encryption'] ?? 'tls')));
        $useImplicitTls = $encryption === 'ssl' || $encryption === 'smtps' || $port === 465;

        return [
            'host' => $panelHost,
            'port' => $port,
            'username' => $username,
            'password' => $password,
            'useImplicitTls' => $useImplicitTls,
        ];
    }

    /**
     * @return array{host: string, port: int, username: string, password: string, useImplicitTls: bool}
     */
    private function smtpCredentialsFromEnvOrThrow(): array
    {
        $host = trim((string) config('mail.mailers.smtp.host', ''));
        $username = config('mail.mailers.smtp.username');
        $password = config('mail.mailers.smtp.password');
        $port = (int) config('mail.mailers.smtp.port', 587);
        $userStr = $username !== null ? trim((string) $username) : '';
        $passStr = $password !== null ? trim((string) $password) : '';
        $scheme = strtolower((string) config('mail.mailers.smtp.scheme', ''));
        $encryption = strtolower((string) env('MAIL_ENCRYPTION', ''));
        $useImplicitTls = $scheme === 'smtps' || $encryption === 'ssl' || $port === 465;

        if ($host === '') {
            throw new \InvalidArgumentException('Configure Gmail/SMTP en este panel (sección Correo Gmail) o MAIL_HOST en .env.');
        }
        if ($userStr === '') {
            throw new \InvalidArgumentException('Indique el usuario SMTP en el panel o MAIL_USERNAME en .env (con Gmail, su correo completo).');
        }
        if ($passStr === '') {
            throw new \InvalidArgumentException('Guarde la contraseña de aplicación de Gmail en el panel o MAIL_PASSWORD en .env.');
        }

        return [
            'host' => $host,
            'port' => $port > 0 ? $port : 587,
            'username' => $userStr,
            'password' => $passStr,
            'useImplicitTls' => $useImplicitTls,
        ];
    }

    /**
     * Credenciales efectivas tras aplicar el formulario (merge con BD si faltan claves).
     * Sirve para comprobar AUTH contra el servidor antes de persistir.
     *
     * @param  array<string, mixed>  $data
     * @return array{host: string, port: int, username: string, password: string, useImplicitTls: bool}|null
     */
    public function smtpCredentialsForConnectionTest(array $data): ?array
    {
        $state = $this->mergedSmtpStateFromForm($data);
        if ($state['host'] === '') {
            return null;
        }
        $enc = strtolower($state['encryption']);
        $useImplicitTls = $enc === 'ssl' || $enc === 'smtps' || $state['port'] === 465;

        return [
            'host' => $state['host'],
            'port' => $state['port'],
            'username' => $state['username'],
            'password' => $state['password_plain'],
            'useImplicitTls' => $useImplicitTls,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function persistFromForm(array $data): void
    {
        $this->writeSmtpState($this->mergedSmtpStateFromForm($data));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{host: string, port: int, encryption: string, username: string, password_plain: string, password_encrypted: string|null}
     */
    private function mergedSmtpStateFromForm(array $data): array
    {
        $current = $this->rawConfig();
        $passwordEncrypted = $current['password_encrypted'] ?? null;

        $host = array_key_exists('smtp_host', $data)
            ? trim((string) $data['smtp_host'])
            : trim((string) ($current['host'] ?? ''));

        if ($host === '') {
            return [
                'host' => '',
                'port' => 587,
                'encryption' => 'tls',
                'username' => '',
                'password_plain' => '',
                'password_encrypted' => null,
            ];
        }

        $port = array_key_exists('smtp_port', $data)
            ? (int) $data['smtp_port']
            : (int) ($current['port'] ?? 587);
        if ($port <= 0) {
            $port = 587;
        }

        $encryption = array_key_exists('smtp_encryption', $data)
            ? trim((string) $data['smtp_encryption'])
            : trim((string) ($current['encryption'] ?? 'tls'));

        $username = array_key_exists('smtp_username', $data)
            ? trim((string) $data['smtp_username'])
            : trim((string) ($current['username'] ?? ''));

        $smtpPasswordInput = array_key_exists('smtp_password', $data)
            ? trim((string) $data['smtp_password'])
            : '';

        if ($smtpPasswordInput !== '') {
            $passwordEncrypted = Crypt::encryptString($smtpPasswordInput);
            $plain = preg_replace('/\s+/', '', $smtpPasswordInput) ?? $smtpPasswordInput;
        } elseif (! empty($data['clear_smtp_password'])) {
            $passwordEncrypted = null;
            $plain = '';
        } else {
            $decrypted = $this->decryptValue($passwordEncrypted);
            $plain = $decrypted !== null
                ? (preg_replace('/\s+/', '', trim($decrypted)) ?? trim($decrypted))
                : '';
        }

        return [
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'username' => $username,
            'password_plain' => $plain,
            'password_encrypted' => $passwordEncrypted,
        ];
    }

    /**
     * @param  array{host: string, port: int, encryption: string, username: string, password_plain: string, password_encrypted: string|null}  $state
     */
    private function writeSmtpState(array $state): void
    {
        AppSetting::setJsonValue(AppSetting::KEY_MAIL_RUNTIME_TRANSPORT, [
            'host' => $state['host'],
            'port' => $state['port'],
            'encryption' => $state['encryption'],
            'username' => $state['username'],
            'password_encrypted' => $state['password_encrypted'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rawConfig(): array
    {
        try {
            $row = AppSetting::query()->where('key', AppSetting::KEY_MAIL_RUNTIME_TRANSPORT)->first();
        } catch (\Throwable) {
            return [];
        }

        return is_array($row?->value) ? $row->value : [];
    }

    private function decryptValue(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
