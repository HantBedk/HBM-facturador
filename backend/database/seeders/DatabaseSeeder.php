<?php

namespace Database\Seeders;

use App\Models\Company;
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
            ]
        );

        Company::query()->updateOrCreate(
            ['nit' => '800555444-1'],
            [
                'nombre' => 'Tech Solutions Colombia S.A.S.',
                'estado' => Company::ESTADO_ACTIVO,
            ]
        );

        Company::query()->updateOrCreate(
            ['nombre' => 'Cliente varios (sin NIT)'],
            [
                'nit' => null,
                'estado' => Company::ESTADO_ACTIVO,
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

        $this->call(DemoPublicInvoiceSeeder::class);
    }
}
