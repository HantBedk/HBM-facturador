<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\ServiceCatalog;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Company::query()->updateOrCreate(
            ['nit' => '900111222-3'],
            [
                'nombre' => 'Ferretería SYF',
                'estado' => Company::ESTADO_ACTIVO,
                'factura_sigla' => 'SYF',
            ]
        );

        Company::query()->updateOrCreate(
            ['nit' => '800555444-1'],
            [
                'nombre' => 'Tech Solutions Colombia S.A.S.',
                'estado' => Company::ESTADO_ACTIVO,
                'factura_sigla' => 'TEC',
            ]
        );

        Company::query()->updateOrCreate(
            ['nombre' => 'Cliente varios (sin NIT)'],
            [
                'nit' => null,
                'estado' => Company::ESTADO_ACTIVO,
                'factura_sigla' => 'CVA',
            ]
        );

        /*
         * Contraseña en texto plano: el modelo User usa cast 'hashed' y aplica Hash::make al guardar.
         * Usar Hash::make() aquí provocaba doble hash y el login fallaba siempre.
         */
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
    }
}
