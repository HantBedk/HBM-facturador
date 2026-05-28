<?php

namespace App\Console\Commands;

use App\Services\DevEmpresaSistemaSnapshotService;
use Illuminate\Console\Command;

class HbmEnsureDevEmpresaSistemaCommand extends Command
{
    protected $signature = 'hbm:ensure-dev-empresa-sistema';

    protected $description = 'Respalda o restaura Empresa del sistema (perfil para factura PDF y remitente de correo) en desarrollo local';

    public function handle(DevEmpresaSistemaSnapshotService $snapshots): int
    {
        foreach ($snapshots->syncForDevEnvironment() as $line) {
            $this->info($line);
        }

        $this->newLine();
        $this->comment('Edite los datos en Admin → Configuración → Empresa del sistema.');
        $this->comment('Al volver a ejecutar este comando se actualizará el respaldo JSON con lo registrado en BD.');

        return self::SUCCESS;
    }
}
