<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SystemOrganizationProfileService
{
    /**
     * @return array<string, string>
     */
    public function profileForForm(): array
    {
        $raw = $this->rawProfile();

        return [
            'legal_name' => trim((string) ($raw['legal_name'] ?? '')),
            'trade_name' => trim((string) ($raw['trade_name'] ?? '')),
            'nit' => trim((string) ($raw['nit'] ?? '')),
            'email' => trim((string) ($raw['email'] ?? '')),
            'phone' => trim((string) ($raw['phone'] ?? '')),
            'phone_secondary' => trim((string) ($raw['phone_secondary'] ?? '')),
            'website' => trim((string) ($raw['website'] ?? '')),
            'address_line1' => trim((string) ($raw['address_line1'] ?? '')),
            'address_line2' => trim((string) ($raw['address_line2'] ?? '')),
            'city' => trim((string) ($raw['city'] ?? '')),
            'department' => trim((string) ($raw['department'] ?? '')),
            'country' => trim((string) ($raw['country'] ?? '')),
            'postal_code' => trim((string) ($raw['postal_code'] ?? '')),
        ];
    }

    /**
     * Nombre comercial o razón social usado como {{nombre_sistema}} en plantillas de correo (bienvenida, factura, mantenimiento).
     */
    public function displayNameForMail(): string
    {
        $p = $this->profileForForm();
        $trade = $p['trade_name'];
        if ($trade !== '') {
            return $trade;
        }
        $legal = $p['legal_name'];
        if ($legal !== '') {
            return $legal;
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function persist(array $validated): void
    {
        $cur = $this->rawProfile();
        $pick = function (string $key) use ($validated, $cur): string {
            return array_key_exists($key, $validated)
                ? trim((string) $validated[$key])
                : trim((string) ($cur[$key] ?? ''));
        };

        AppSetting::setJsonValue(AppSetting::KEY_SYSTEM_ORGANIZATION_PROFILE, [
            'legal_name' => $this->sanitizeLine($pick('legal_name'), 255),
            'trade_name' => $this->sanitizeLine($pick('trade_name'), 255),
            'nit' => $this->sanitizeLine($pick('nit'), 100),
            'email' => $this->sanitizeLine($pick('email'), 255),
            'phone' => $this->sanitizeLine($pick('phone'), 64),
            'phone_secondary' => $this->sanitizeLine($pick('phone_secondary'), 64),
            'website' => $this->sanitizeLine($pick('website'), 255),
            'address_line1' => $this->sanitizeLine($pick('address_line1'), 255),
            'address_line2' => $this->sanitizeLine($pick('address_line2'), 255),
            'city' => $this->sanitizeLine($pick('city'), 120),
            'department' => $this->sanitizeLine($pick('department'), 120),
            'country' => $this->sanitizeLine($pick('country'), 120),
            'postal_code' => $this->sanitizeLine($pick('postal_code'), 32),
        ]);
    }

    /**
     * @return array{relative_path: string, original_filename: string}|null
     */
    public function logoMeta(): ?array
    {
        $row = AppSetting::query()->where('key', AppSetting::KEY_SYSTEM_ORGANIZATION_LOGO)->first();
        if ($row === null || ! is_array($row->value)) {
            return null;
        }
        $rel = trim((string) ($row->value['relative_path'] ?? ''));
        if ($rel === '' || ! Storage::disk('local')->exists($rel)) {
            return null;
        }
        $orig = trim((string) ($row->value['original_filename'] ?? ''));
        if ($orig === '') {
            $orig = 'logo';
        }

        return ['relative_path' => $rel, 'original_filename' => $orig];
    }

    public function logoConfigured(): bool
    {
        return $this->logoMeta() !== null;
    }

    public function logoAbsolutePath(): ?string
    {
        $m = $this->logoMeta();
        if ($m === null) {
            return null;
        }

        return Storage::disk('local')->path($m['relative_path']);
    }

    public function storeLogo(UploadedFile $file): void
    {
        $this->deleteStoredLogoFileOnly();
        $ext = strtolower($file->getClientOriginalExtension() ?: 'png');
        if (! in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
            $ext = 'png';
        }
        $storedName = Str::uuid()->toString().'.'.$ext;
        $prefix = 'system-organization';
        Storage::disk('local')->putFileAs($prefix, $file, $storedName);
        $relative = $prefix.'/'.$storedName;
        $orig = basename($file->getClientOriginalName());
        $orig = mb_substr($orig, 0, 200);
        if ($orig === '') {
            $orig = 'logo.'.$ext;
        }
        AppSetting::setJsonValue(AppSetting::KEY_SYSTEM_ORGANIZATION_LOGO, [
            'relative_path' => $relative,
            'original_filename' => $orig,
        ]);
    }

    public function deleteLogo(): void
    {
        $this->deleteStoredLogoFileOnly();
        AppSetting::query()->where('key', AppSetting::KEY_SYSTEM_ORGANIZATION_LOGO)->delete();
    }

    private function deleteStoredLogoFileOnly(): void
    {
        $row = AppSetting::query()->where('key', AppSetting::KEY_SYSTEM_ORGANIZATION_LOGO)->first();
        if ($row === null || ! is_array($row->value)) {
            return;
        }
        $rel = trim((string) ($row->value['relative_path'] ?? ''));
        if ($rel !== '' && Storage::disk('local')->exists($rel)) {
            Storage::disk('local')->delete($rel);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function rawProfile(): array
    {
        $row = AppSetting::query()->where('key', AppSetting::KEY_SYSTEM_ORGANIZATION_PROFILE)->first();
        if ($row === null || ! is_array($row->value)) {
            return [];
        }

        return $row->value;
    }

    private function sanitizeLine(string $value, int $max): string
    {
        $v = strip_tags($value);

        return mb_substr(trim($v), 0, $max);
    }
}
