<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Un solo comando para no dejar la BD sin usuarios tras migrar (caso típico: login 401).
 */
class HbmSyncDatabase extends Command
{
    protected $signature = 'hbm:sync
                            {--fresh : migrate:fresh --seed (borra todos los datos; solo desarrollo)}';

    protected $description = 'Migraciones + DatabaseSeeder (usuarios demo, empresas, catálogo).';

    public function handle(): int
    {
        if ($this->option('fresh')) {
            $this->call('migrate:fresh', [
                '--force' => true,
                '--seed' => true,
            ]);
        } else {
            $this->call('migrate', ['--force' => true]);
            $this->call('db:seed', ['--force' => true]);
        }

        $this->newLine();
        $this->info('Acceso demo (ver DatabaseSeeder): admin@hbm.local, sadmin@hbm.local, tc1@hbm.local');

        return self::SUCCESS;
    }
}
