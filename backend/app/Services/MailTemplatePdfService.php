<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * PDF opcionales adjuntos a correos transaccionales (bienvenida, factura, mantenimiento).
 */
class MailTemplatePdfService
{
    public const KIND_WELCOME = 'welcome';

    public const KIND_INVOICE_SUPPLEMENT = 'invoice_supplement';

    public const KIND_MAINTENANCE_SUPPLEMENT = 'maintenance_supplement';

    /**
     * @return array{key: string, prefix: string}
     */
    private function resolve(string $kind): array
    {
        return match ($kind) {
            self::KIND_WELCOME => [
                'key' => AppSetting::KEY_MAIL_COMPANY_WELCOME_PDF,
                'prefix' => 'mail-company-welcome',
            ],
            self::KIND_INVOICE_SUPPLEMENT => [
                'key' => AppSetting::KEY_MAIL_INVOICE_SUPPLEMENT_PDF,
                'prefix' => 'mail-invoice-supplement',
            ],
            self::KIND_MAINTENANCE_SUPPLEMENT => [
                'key' => AppSetting::KEY_MAIL_MAINTENANCE_SUPPLEMENT_PDF,
                'prefix' => 'mail-maintenance-supplement',
            ],
            default => throw new \InvalidArgumentException('kind'),
        };
    }

    /**
     * @return array{relative_path: string, original_filename: string}|null
     */
    public function meta(string $kind): ?array
    {
        $r = $this->resolve($kind);
        $row = AppSetting::query()->where('key', $r['key'])->first();
        if ($row === null || ! is_array($row->value)) {
            return null;
        }
        $rel = trim((string) ($row->value['relative_path'] ?? ''));
        if ($rel === '' || ! Storage::disk('local')->exists($rel)) {
            return null;
        }
        $orig = trim((string) ($row->value['original_filename'] ?? ''));
        if ($orig === '') {
            $orig = 'documento.pdf';
        }

        return ['relative_path' => $rel, 'original_filename' => $orig];
    }

    public function configured(string $kind): bool
    {
        return $this->meta($kind) !== null;
    }

    public function absolutePath(string $kind): ?string
    {
        $m = $this->meta($kind);
        if ($m === null) {
            return null;
        }

        return Storage::disk('local')->path($m['relative_path']);
    }

    public function store(string $kind, UploadedFile $file): void
    {
        $r = $this->resolve($kind);
        $this->deleteStoredFileOnly($r['key']);
        $storedName = Str::uuid()->toString().'.pdf';
        Storage::disk('local')->putFileAs($r['prefix'], $file, $storedName);
        $relative = $r['prefix'].'/'.$storedName;
        $orig = basename($file->getClientOriginalName());
        $orig = mb_substr($orig, 0, 200);
        if ($orig === '' || ! str_ends_with(strtolower($orig), '.pdf')) {
            $orig = 'documento.pdf';
        }
        AppSetting::setJsonValue($r['key'], [
            'relative_path' => $relative,
            'original_filename' => $orig,
        ]);
    }

    public function deleteAll(string $kind): void
    {
        $r = $this->resolve($kind);
        $this->deleteStoredFileOnly($r['key']);
        AppSetting::query()->where('key', $r['key'])->delete();
    }

    private function deleteStoredFileOnly(string $settingKey): void
    {
        $row = AppSetting::query()->where('key', $settingKey)->first();
        if ($row === null || ! is_array($row->value)) {
            return;
        }
        $rel = trim((string) ($row->value['relative_path'] ?? ''));
        if ($rel !== '' && Storage::disk('local')->exists($rel)) {
            Storage::disk('local')->delete($rel);
        }
    }
}
