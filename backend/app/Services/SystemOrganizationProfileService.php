<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
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
            'tax_regimen' => trim((string) ($raw['tax_regimen'] ?? '')),
        ];
    }

    /**
     * Bloque emisor para PDF / vista previa de factura (empresa operadora, no el cliente).
     *
     * @return array{nombre: string, nit: string, direccion: string, telefono: string, correo: string, regimen: string, logo_data_uri: ?string}
     */
    public function issuerBlockForInvoice(): array
    {
        $p = $this->profileForForm();
        $cfg = config('billing.issuer', []);

        $nombre = $p['trade_name'] !== ''
            ? $p['trade_name']
            : ($p['legal_name'] !== '' ? $p['legal_name'] : trim((string) ($cfg['nombre'] ?? '')));

        $nit = $p['nit'] !== '' ? $p['nit'] : trim((string) ($cfg['nit'] ?? ''));
        $direccion = $this->formattedAddressForInvoice($p);
        if ($direccion === '') {
            $direccion = trim((string) ($cfg['direccion'] ?? ''));
        }

        $telefono = $p['phone'] !== ''
            ? $p['phone']
            : ($p['phone_secondary'] !== '' ? $p['phone_secondary'] : trim((string) ($cfg['telefono'] ?? '')));

        $correo = $p['email'] !== '' ? $p['email'] : trim((string) ($cfg['correo'] ?? ''));

        $regimen = $p['tax_regimen'] !== '' ? $p['tax_regimen'] : trim((string) ($cfg['regimen'] ?? ''));

        return [
            'nombre' => $nombre,
            'nit' => $nit,
            'direccion' => $direccion,
            'telefono' => $telefono,
            'correo' => $correo,
            'regimen' => $regimen,
            'logo_data_uri' => $this->logoDataUriForInvoice(),
        ];
    }

    /**
     * Campos obligatorios del emisor (texto) y aviso de logo para el panel.
     *
     * @return array{ready: bool, missing: list<string>, logo_configured: bool}
     */
    public function invoiceEmitterStatus(): array
    {
        $block = $this->issuerBlockForInvoice();
        $missing = [];
        if (trim($block['nombre']) === '') {
            $missing[] = 'nombre';
        }
        if (trim($block['nit']) === '') {
            $missing[] = 'nit';
        }
        if (trim($block['direccion']) === '') {
            $missing[] = 'direccion';
        }
        if (trim($block['telefono']) === '') {
            $missing[] = 'telefono';
        }
        if (trim($block['correo']) === '') {
            $missing[] = 'correo';
        }

        return [
            'ready' => $missing === [],
            'missing' => $missing,
            'logo_configured' => $this->logoConfiguredForInvoice(),
        ];
    }

    public function logoConfiguredForInvoice(): bool
    {
        if ($this->logoMeta() !== null) {
            return true;
        }

        return $this->configLogoReadable();
    }

    public function logoDataUriForInvoice(): ?string
    {
        $path = $this->logoAbsolutePath();
        if ($path !== null && is_readable($path)) {
            return $this->fileToDataUri($path);
        }

        return $this->configLogoDataUri();
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

    /** Correo de contacto de Empresa del sistema (remitente y {{nombre_sistema}} en plantillas). */
    public function emailForMail(): string
    {
        $email = trim($this->profileForForm()['email'] ?? '');
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }

        return '';
    }

    /** Hay al menos un dato de perfil o logo guardado en BD. */
    public function profileHasContent(): bool
    {
        if ($this->logoConfigured()) {
            return true;
        }

        foreach ($this->profileForForm() as $value) {
            if (trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
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
            'tax_regimen' => $this->sanitizeLine($pick('tax_regimen'), 255),
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

    /**
     * @param  array<string, string>  $p
     */
    private function formattedAddressForInvoice(array $p): string
    {
        $line1 = trim((string) ($p['address_line1'] ?? ''));
        $line2 = trim((string) ($p['address_line2'] ?? ''));
        $cityDept = trim(implode(', ', array_filter([
            trim((string) ($p['city'] ?? '')),
            trim((string) ($p['department'] ?? '')),
        ])));
        $country = trim((string) ($p['country'] ?? ''));
        $postal = trim((string) ($p['postal_code'] ?? ''));

        $parts = array_values(array_filter([$line1, $line2, $cityDept, $country, $postal]));

        return implode(' — ', $parts);
    }

    private function configLogoReadable(): bool
    {
        $path = (string) config('billing.logo_path', '');
        if ($path === '') {
            return false;
        }

        $full = $this->isAbsolutePath($path)
            ? $path
            : public_path(ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR));

        return is_readable($full);
    }

    private function configLogoDataUri(): ?string
    {
        $path = (string) config('billing.logo_path', '');
        if ($path === '') {
            return null;
        }

        $full = $this->isAbsolutePath($path)
            ? $path
            : public_path(ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR));

        if (! is_readable($full)) {
            return null;
        }

        return $this->fileToDataUri($full);
    }

    private function fileToDataUri(string $full): ?string
    {
        $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/png',
        };

        $binary = @File::get($full);
        if ($binary === false || $binary === '') {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($binary);
    }

    private function isAbsolutePath(string $path): bool
    {
        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
    }
}
