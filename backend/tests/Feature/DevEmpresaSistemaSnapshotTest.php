<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Services\DevEmpresaSistemaSnapshotService;
use App\Services\SystemOrganizationProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DevEmpresaSistemaSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $path = app(DevEmpresaSistemaSnapshotService::class)->snapshotPath();
        if (is_file($path)) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_exports_profile_when_database_has_content(): void
    {
        app(SystemOrganizationProfileService::class)->persist([
            'trade_name' => 'Operador Real',
            'email' => 'ops@real.test',
            'nit' => '900.111-1',
        ]);

        $messages = app(DevEmpresaSistemaSnapshotService::class)->syncForDevEnvironment();

        $this->assertNotEmpty($messages);
        $path = app(DevEmpresaSistemaSnapshotService::class)->snapshotPath();
        $this->assertFileExists($path);
        $json = json_decode((string) File::get($path), true);
        $this->assertSame('Operador Real', $json['profile']['trade_name']);
    }

    public function test_imports_snapshot_when_database_empty(): void
    {
        $path = app(DevEmpresaSistemaSnapshotService::class)->snapshotPath();
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'profile' => [
                'legal_name' => 'Importada SAS',
                'trade_name' => 'Importada',
                'email' => 'import@hbm.local',
                'nit' => '800.1',
            ],
            'logo_configured' => false,
        ], JSON_THROW_ON_ERROR));

        app(DevEmpresaSistemaSnapshotService::class)->syncForDevEnvironment();

        $row = AppSetting::query()->where('key', AppSetting::KEY_SYSTEM_ORGANIZATION_PROFILE)->first();
        $this->assertIsArray($row->value);
        $this->assertSame('Importada', $row->value['trade_name']);
    }
}
