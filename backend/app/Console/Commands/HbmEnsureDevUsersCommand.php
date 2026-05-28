<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\DevEmpresaSistemaSnapshotService;
use Database\Seeders\EnsureDevLoginSeeder;
use Illuminate\Console\Command;

/**
 * Ejecuta {@see EnsureDevLoginSeeder}: crea usuarios demo solo si la tabla `users` está vacía.
 */
class HbmEnsureDevUsersCommand extends Command
{
    protected $signature = 'hbm:ensure-dev-users';

    protected $description = 'Asegura usuarios de login local (admin, super_admin, empleado) si users está vacío';

    public function handle(DevEmpresaSistemaSnapshotService $empresaSnapshots): int
    {
        $hadUsers = User::query()->exists();

        $this->call('db:seed', [
            '--class' => EnsureDevLoginSeeder::class,
            '--force' => true,
        ]);

        if ($hadUsers) {
            $this->warn('La tabla users ya tenía filas: EnsureDevLoginSeeder no crea duplicados.');
            $this->comment('Si necesita resetear credenciales, use el panel de usuarios o corrija datos en BD.');
        } else {
            $this->info('Usuarios demo creados (solo si la tabla estaba vacía).');
        }

        $this->newLine();
        $this->line('Correos: admin@hbm.local, sadmin@hbm.local, tc1@hbm.local');
        $this->comment('Contraseña: ver database/seeders/EnsureDevLoginSeeder.php');

        $this->newLine();
        $this->info('Empresa del sistema (factura PDF / remitente correo):');
        foreach ($empresaSnapshots->syncForDevEnvironment() as $line) {
            $this->line('  '.$line);
        }

        return self::SUCCESS;
    }
}
