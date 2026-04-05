<?php

namespace App\Console\Commands;

use App\Models\Company;
use Database\Seeders\EnsureDevLoginSeeder;
use Illuminate\Console\Command;

/**
 * Migrate + asegurar login en dev sin volver a ejecutar el DatabaseSeeder completo (evita pisar datos).
 */
class HbmSyncDatabase extends Command
{
    protected $signature = 'hbm:sync
                            {--fresh : migrate:fresh --seed (borra TODA la base; solo desarrollo)}
                            {--demo : Tras migrate, ejecuta DatabaseSeeder completo (empresas, catálogo, demo factura)}';

    protected $description = 'Migraciones + usuarios demo solo si hace falta; seeder completo solo con --demo o BD sin empresas.';

    public function handle(): int
    {
        if ($this->option('fresh')) {
            $this->call('migrate:fresh', [
                '--force' => true,
                '--seed' => true,
            ]);
            $this->newLine();
            $this->info('Base recreada. Acceso demo: admin@hbm.local (contraseña en DatabaseSeeder).');

            return self::SUCCESS;
        }

        $this->call('migrate', ['--force' => true]);

        $this->call('db:seed', [
            '--class' => EnsureDevLoginSeeder::class,
            '--force' => true,
        ]);

        $runFullSeeder = $this->option('demo') || ! Company::query()->exists();

        if ($runFullSeeder) {
            if ($this->option('demo')) {
                $this->warn('Aplicando DatabaseSeeder completo (--demo): puede actualizar registros con las mismas claves que el seed (NIT, nombres de catálogo, etc.).');
            } else {
                $this->warn('No hay empresas: aplicando DatabaseSeeder completo (primera carga).');
            }
            $this->call('db:seed', ['--force' => true]);
        }

        $this->newLine();
        $this->info('Listo. Si existen usuarios demo: admin@hbm.local, sadmin@hbm.local, tc1@hbm.local (contraseña en EnsureDevLoginSeeder / DatabaseSeeder).');
        $this->comment('Datos demo extra: php artisan hbm:sync --demo   |   Base desde cero: php artisan hbm:sync --fresh');

        return self::SUCCESS;
    }
}
