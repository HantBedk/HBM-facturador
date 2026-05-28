<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class DatabaseBackupCommand extends Command
{
    protected $signature = 'db:backup {--force : Ejecutar aunque DB_BACKUP_ENABLED sea false (solo uso manual)}';

    protected $description = 'Genera un respaldo de la base de datos (MySQL/MariaDB o archivo SQLite).';

    public function handle(): int
    {
        $enabled = config('database.backup.enabled', false);
        if (! $enabled && ! $this->option('force')) {
            $this->warn('Respaldo deshabilitado. Defina DB_BACKUP_ENABLED=true o use --force.');

            return self::SUCCESS;
        }

        $dir = (string) config('database.backup.path', storage_path('app/backups'));
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $connection = config('database.default');
        if ($connection === 'sqlite') {
            return $this->backupSqlite($dir);
        }

        if (in_array($connection, ['mysql', 'mariadb'], true)) {
            return $this->backupMysqlFamily($dir, $connection);
        }

        $this->error('Conexión '.$connection.' no soportada para respaldo automático.');

        return self::FAILURE;
    }

    private function backupSqlite(string $dir): int
    {
        $path = (string) config('database.connections.sqlite.database');
        if ($path === ':memory:') {
            $this->warn('SQLite en memoria: no hay archivo que respaldar.');

            return self::SUCCESS;
        }

        if (! is_readable($path)) {
            $this->error('No se puede leer el archivo SQLite: '.$path);

            return self::FAILURE;
        }

        $name = 'sqlite-'.now()->format('Y-m-d-His').'.sqlite';
        $dest = $dir.DIRECTORY_SEPARATOR.$name;
        File::copy($path, $dest);
        $this->info('Respaldo SQLite: '.$dest);
        $this->pruneOldFiles($dir);

        return self::SUCCESS;
    }

    private function backupMysqlFamily(string $dir, string $connection): int
    {
        $cfg = config('database.connections.'.$connection);
        $binary = (string) config('database.backup.mysqldump_binary', 'mysqldump');
        $host = (string) ($cfg['host'] ?? '127.0.0.1');
        $port = (string) ($cfg['port'] ?? '3306');
        $database = (string) ($cfg['database'] ?? '');
        $user = (string) ($cfg['username'] ?? 'root');
        $password = (string) ($cfg['password'] ?? '');

        $name = 'mysql-'.now()->format('Y-m-d-His').'.sql';
        $dest = $dir.DIRECTORY_SEPARATOR.$name;

        $args = [
            $binary,
            '--single-transaction',
            '--no-tablespaces',
            '-h', $host,
            '-P', $port,
            '-u', $user,
            $database,
        ];

        $result = Process::timeout(600)
            ->env(['MYSQL_PWD' => $password])
            ->run($args);

        if (! $result->successful()) {
            $err = trim($result->errorOutput().$result->output());
            $this->error('mysqldump falló. Instale el cliente MySQL o defina MYSQLDUMP_PATH. Detalle: '.$err);

            return self::FAILURE;
        }

        File::put($dest, $result->output());
        $this->info('Respaldo MySQL: '.$dest);
        $this->pruneOldFiles($dir);

        return self::SUCCESS;
    }

    private function pruneOldFiles(string $dir): void
    {
        $days = (int) config('database.backup.retain_days', 0);
        if ($days < 1) {
            return;
        }

        $cutoff = now()->subDays($days)->timestamp;
        foreach (File::files($dir) as $path) {
            $base = basename((string) $path);
            if (! Str::endsWith($base, ['.sql', '.sqlite'])) {
                continue;
            }
            if (@filemtime((string) $path) < $cutoff) {
                File::delete($path);
                $this->line('Eliminado respaldo antiguo: '.$base);
            }
        }
    }
}
