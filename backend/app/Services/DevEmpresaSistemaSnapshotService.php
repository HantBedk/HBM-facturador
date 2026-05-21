<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\File;

/**
 * Respaldo local de Empresa del sistema para desarrollo (HBM-UsuariosDev.cmd).
 */
class DevEmpresaSistemaSnapshotService
{
    public function __construct(
        private readonly SystemOrganizationProfileService $organization,
    ) {}

    public function snapshotPath(): string
    {
        return storage_path('app/local-dev/empresa-sistema.snapshot.json');
    }

    /**
     * @return list<string> mensajes para consola
     */
    public function syncForDevEnvironment(): array
    {
        $this->ensureDirectory();
        $messages = [];

        if ($this->organization->profileHasContent()) {
            $this->exportCurrentProfileSnapshot();
            $messages[] = 'Empresa del sistema: respaldo actualizado en storage/app/local-dev/empresa-sistema.snapshot.json';

            return $messages;
        }

        if ($this->importSnapshotIfExists()) {
            $messages[] = 'Empresa del sistema: datos restaurados desde empresa-sistema.snapshot.json';

            return $messages;
        }

        $this->seedDefaultProfile();
        $this->exportSnapshot();
        $messages[] = 'Empresa del sistema: perfil demo inicial creado (edítelo en Configuración → Empresa del sistema).';

        return $messages;
    }

    private function ensureDirectory(): void
    {
        $dir = dirname($this->snapshotPath());
        if (! is_dir($dir)) {
            File::ensureDirectoryExists($dir);
        }
    }

    public function exportCurrentProfileSnapshot(): void
    {
        if (! $this->organization->profileHasContent()) {
            return;
        }
        $this->ensureDirectory();
        $this->exportSnapshot();
    }

    private function exportSnapshot(): void
    {
        $payload = [
            'exported_at' => now()->toIso8601String(),
            'profile' => $this->organization->profileForForm(),
            'logo_configured' => $this->organization->logoConfigured(),
        ];

        File::put(
            $this->snapshotPath(),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n"
        );
    }

    private function importSnapshotIfExists(): bool
    {
        $path = $this->snapshotPath();
        if (! is_readable($path)) {
            return false;
        }

        $raw = json_decode((string) File::get($path), true);
        if (! is_array($raw) || ! is_array($raw['profile'] ?? null)) {
            return false;
        }

        $this->organization->persist($raw['profile']);

        return true;
    }

    private function seedDefaultProfile(): void
    {
        $this->organization->persist([
            'legal_name' => 'HBM Soluciones SAS',
            'trade_name' => 'HBM Facturador',
            'nit' => '900000000-1',
            'email' => 'facturacion@hbm.local',
            'phone' => '+57 300 000 0000',
            'phone_secondary' => '',
            'website' => '',
            'address_line1' => 'Calle demo 1 # 2-3',
            'address_line2' => '',
            'city' => 'Bogotá',
            'department' => 'Cundinamarca',
            'country' => 'Colombia',
            'postal_code' => '',
            'tax_regimen' => 'Régimen simplificado',
        ]);
    }
}
