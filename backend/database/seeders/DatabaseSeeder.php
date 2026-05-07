<?php

namespace Database\Seeders;

use App\Models\AppSetting;
use App\Models\Company;
use App\Models\ServiceCatalog;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Clave por factura_sigla (única): evita duplicar si ya existe fila con la sigla pero otro NIT.
        Company::query()->updateOrCreate(
            ['factura_sigla' => 'SYF'],
            [
                'nit' => '900111222-3',
                'nombre' => 'Ferretería SYF',
                'estado' => Company::ESTADO_ACTIVO,
            ]
        );

        Company::query()->updateOrCreate(
            ['factura_sigla' => 'TEC'],
            [
                'nit' => '800555444-1',
                'nombre' => 'Tech Solutions Colombia S.A.S.',
                'estado' => Company::ESTADO_ACTIVO,
            ]
        );

        Company::query()->updateOrCreate(
            ['factura_sigla' => 'CVA'],
            [
                'nit' => null,
                'nombre' => 'Cliente varios (sin NIT)',
                'estado' => Company::ESTADO_ACTIVO,
            ]
        );

        User::query()->updateOrCreate(
            ['correo' => 'admin@hbm.local'],
            [
                'nombre' => 'Administrador',
                'password' => '1qwer432',
                'rol' => User::ROL_ADMIN,
                'estado' => User::ESTADO_ACTIVO,
            ]
        );

        User::query()->updateOrCreate(
            ['correo' => 'sadmin@hbm.local'],
            [
                'nombre' => 'Super administrador',
                'password' => '1qwer432',
                'rol' => User::ROL_SUPER_ADMIN,
                'estado' => User::ESTADO_ACTIVO,
            ]
        );

        User::query()->updateOrCreate(
            ['correo' => 'tc1@hbm.local'],
            [
                'nombre' => 'Técnico 1',
                'password' => '1qwer432',
                'rol' => User::ROL_EMPLEADO,
                'estado' => User::ESTADO_ACTIVO,
            ]
        );

        AppSetting::setValue(AppSetting::KEY_TECHNICIAN_CATALOG_DISCOUNT_PERCENT, '10');

        $catalogSeeds = [
            ['name' => 'Revisión de equipo', 'description' => 'Inspección y diagnóstico inicial del equipo o instalación.', 'base_price' => 75000],
            ['name' => 'Formateo', 'description' => 'Respaldo opcional, formateo de disco e instalación de sistema operativo base.', 'base_price' => 120000],
            ['name' => 'Instalación de software', 'description' => 'Instalación y configuración de aplicaciones según licencias del cliente.', 'base_price' => 85000],
            ['name' => 'Mantenimiento preventivo', 'description' => 'Limpieza, actualizaciones de seguridad y verificación de funcionamiento.', 'base_price' => 95000],
        ];
        foreach ($catalogSeeds as $row) {
            ServiceCatalog::query()->updateOrCreate(
                ['name' => $row['name']],
                [
                    'description' => $row['description'],
                    'base_price' => $row['base_price'],
                    'status' => ServiceCatalog::STATUS_ACTIVO,
                ]
            );
        }

        $this->call(DemoPublicInvoiceSeeder::class);
        $this->call(InventoryInternalDemoSeeder::class);
    }
}
