<?php

namespace App\Services;

use App\Models\AppSetting;

/**
 * Remitente visible (From) de correos del panel: prioriza Empresa del sistema.
 */
class MailSenderIdentityService
{
    public function __construct(
        private readonly SystemOrganizationProfileService $organization,
        private readonly MailRuntimeSettingsService $mailRuntime,
    ) {}

    public function effectiveAddress(): string
    {
        $org = $this->organization->emailForMail();
        if ($org !== '') {
            return $org;
        }

        $stored = $this->storedFrom();
        $addr = trim((string) ($stored['address'] ?? ''));
        if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL)) {
            return $addr;
        }

        $smtpUser = trim((string) ($this->mailRuntime->publicConfig()['username'] ?? ''));
        if ($smtpUser !== '' && filter_var($smtpUser, FILTER_VALIDATE_EMAIL)) {
            return $smtpUser;
        }

        return trim((string) config('mail.from.address', ''));
    }

    public function effectiveName(): string
    {
        $org = $this->organization->displayNameForMail();
        if ($org !== '') {
            return $org;
        }

        $stored = $this->storedFrom();
        $name = trim((string) ($stored['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        return trim((string) config('mail.from.name', ''));
    }

    /**
     * @return array{address: string, name: string}
     */
    public function organizationSenderPreview(): array
    {
        return [
            'address' => $this->organization->emailForMail(),
            'name' => $this->organization->displayNameForMail(),
        ];
    }

    public function organizationHasMailSenderFields(): bool
    {
        return $this->organization->emailForMail() !== '' && $this->organization->displayNameForMail() !== '';
    }

    /**
     * Tras guardar SMTP, alinea el remitente guardado con Empresa del sistema (o usuario SMTP si falta correo en empresa).
     */
    public function syncStoredFromOrganization(): void
    {
        $addr = $this->organization->emailForMail();
        if ($addr === '') {
            $smtpUser = trim((string) ($this->mailRuntime->publicConfig()['username'] ?? ''));
            if ($smtpUser !== '' && filter_var($smtpUser, FILTER_VALIDATE_EMAIL)) {
                $addr = $smtpUser;
            }
        }

        $name = $this->organization->displayNameForMail();

        AppSetting::setJsonValue(AppSetting::KEY_MAIL_NOTIFICATIONS_FROM, [
            'address' => $addr,
            'name' => $name,
        ]);
    }

    /**
     * @return array{address: string, name: string}
     */
    private function storedFrom(): array
    {
        $row = AppSetting::query()->where('key', AppSetting::KEY_MAIL_NOTIFICATIONS_FROM)->first();
        if ($row === null || ! is_array($row->value)) {
            return ['address' => '', 'name' => ''];
        }

        return [
            'address' => trim((string) ($row->value['address'] ?? '')),
            'name' => trim((string) ($row->value['name'] ?? '')),
        ];
    }
}
