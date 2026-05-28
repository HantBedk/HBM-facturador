<?php

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\Concerns\SeedsInventoryInternalDemo;
use Illuminate\Database\Seeder;

/**
 * Flujo versionado: inventario interno de demostración (SKU FAKE-LOCAL-*).
 *
 * Idempotente para esos SKU: cada ejecución elimina y vuelve a crear lotes y documentos asociados.
 * Crea cuatro técnicos dedicados (inventory-demo-tc*@hbm.local) si no existen.
 */
class InventoryInternalDemoSeeder extends Seeder
{
    use SeedsInventoryInternalDemo;

    public function run(): void
    {
        $faker = fake('es_CO');

        $admin = User::query()
            ->whereIn('rol', [User::ROL_ADMIN, User::ROL_SUPER_ADMIN])
            ->where('estado', User::ESTADO_ACTIVO)
            ->get()
            ->sortBy(fn (User $u) => $u->rol === User::ROL_ADMIN ? 0 : 1)
            ->first();

        if ($admin === null) {
            if ($this->command !== null) {
                $this->command->warn('InventoryInternalDemoSeeder: no hay administrador activo. Ejecute antes DatabaseSeeder o EnsureDevLoginSeeder.');
            }

            return;
        }

        $techs = collect(range(1, 4))->map(function (int $i) {
            return User::query()->firstOrCreate(
                ['correo' => "inventory-demo-tc{$i}@hbm.local"],
                [
                    'nombre' => 'Inventario demo T'.$i,
                    'password' => '1qwer432',
                    'rol' => User::ROL_EMPLEADO,
                    'estado' => User::ESTADO_ACTIVO,
                ]
            );
        })->values();

        $this->seedInventoryLocationsIfMissing();
        $this->seedInventoryInternalDemoDataset($admin, $techs, $faker);
    }
}
