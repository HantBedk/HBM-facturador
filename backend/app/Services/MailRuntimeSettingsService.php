<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Crypt;

class MailRuntimeSettingsService
{
    /**
     * @return array<string, mixed>
     */
    public function publicConfig(): array
    {
        $cfg = $this->rawConfig();

        return [
            'mailer' => (string) ($cfg['mailer'] ?? config('mail.default', 'smtp')),
            'host' => (string) ($cfg['host'] ?? config('mail.mailers.smtp.host', '')),
            'port' => (int) ($cfg['port'] ?? config('mail.mailers.smtp.port', 587)),
            'encryption' => $cfg['encryption'] ?? config('mail.mailers.smtp.scheme', 'tls'),
            'username' => (string) ($cfg['username'] ?? ''),
            'has_smtp_password' => $this->decryptValue($cfg['password_encrypted'] ?? null) !== null,
            'has_resend_api_key' => $this->decryptValue($cfg['resend_api_key_encrypted'] ?? null) !== null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function persistFromForm(array $data): void
    {
        $current = $this->rawConfig();
        $passwordEncrypted = $current['password_encrypted'] ?? null;
        $resendKeyEncrypted = $current['resend_api_key_encrypted'] ?? null;

        $smtpPassword = trim((string) ($data['smtp_password'] ?? ''));
        if ($smtpPassword !== '') {
            $passwordEncrypted = Crypt::encryptString($smtpPassword);
        } elseif (! empty($data['clear_smtp_password'])) {
            $passwordEncrypted = null;
        }

        $resendApiKey = trim((string) ($data['resend_api_key'] ?? ''));
        if ($resendApiKey !== '') {
            $resendKeyEncrypted = Crypt::encryptString($resendApiKey);
        } elseif (! empty($data['clear_resend_api_key'])) {
            $resendKeyEncrypted = null;
        }

        AppSetting::setJsonValue(AppSetting::KEY_MAIL_RUNTIME_TRANSPORT, [
            'mailer' => (string) ($data['smtp_mailer'] ?? 'smtp'),
            'host' => trim((string) ($data['smtp_host'] ?? '')),
            'port' => (int) ($data['smtp_port'] ?? 587),
            'encryption' => trim((string) ($data['smtp_encryption'] ?? 'tls')),
            'username' => trim((string) ($data['smtp_username'] ?? '')),
            'password_encrypted' => $passwordEncrypted,
            'resend_api_key_encrypted' => $resendKeyEncrypted,
        ]);
    }

    public function applyRuntimeMailConfig(): void
    {
        $cfg = $this->rawConfig();
        if ($cfg === []) {
            return;
        }

        $mailer = (string) ($cfg['mailer'] ?? 'smtp');
        $host = trim((string) ($cfg['host'] ?? ''));
        $username = trim((string) ($cfg['username'] ?? ''));
        $encryption = trim((string) ($cfg['encryption'] ?? 'tls'));
        $port = (int) ($cfg['port'] ?? 587);
        if ($host === '') {
            return;
        }

        $password = $this->decryptValue($cfg['resend_api_key_encrypted'] ?? null);
        if ($password === null || $password === '') {
            $password = $this->decryptValue($cfg['password_encrypted'] ?? null);
        }

        config([
            'mail.default' => $mailer,
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.host' => $host,
            'mail.mailers.smtp.port' => $port > 0 ? $port : 587,
            'mail.mailers.smtp.username' => $username !== '' ? $username : null,
            'mail.mailers.smtp.password' => $password,
            'mail.mailers.smtp.scheme' => $encryption !== '' ? $encryption : null,
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

