<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Solo crea los usuarios demo si la tabla users está vacía (login tras migrate sin borrar datos existentes).
 */
class EnsureDevLoginSeeder extends Seeder
{
    public function run(): void
    {
        if (User::query()->exists()) {
            return;
        }

        /*
         * Contraseña en texto plano: el modelo User usa cast 'hashed'.
         */
        User::query()->create([
            'nombre' => 'Administrador',
            'correo' => 'admin@hbm.local',
            'password' => '1qwer432',
            'rol' => User::ROL_ADMIN,
            'estado' => User::ESTADO_ACTIVO,
        ]);

        User::query()->create([
            'nombre' => 'Super administrador',
            'correo' => 'sadmin@hbm.local',
            'password' => '1qwer432',
            'rol' => User::ROL_SUPER_ADMIN,
            'estado' => User::ESTADO_ACTIVO,
        ]);

        User::query()->create([
            'nombre' => 'Técnico 1',
            'correo' => 'tc1@hbm.local',
            'password' => '1qwer432',
            'rol' => User::ROL_EMPLEADO,
            'estado' => User::ESTADO_ACTIVO,
        ]);
    }
}
