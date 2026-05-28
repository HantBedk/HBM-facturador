<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyRecurringService;
use App\Models\Invoice;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryRental;
use App\Models\InventorySale;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Services\ServiceRegistrySpreadsheetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class AdminBackupController extends Controller
{
    private function dir(): string
    {
        return (string) config('database.backup.path', storage_path('app/backups'));
    }

    private function guard(string $filename): void
    {
        if (! preg_match('/^[\w\-]+\.(sql|sqlite)$/', $filename)) {
            abort(422, 'Nombre de archivo inválido.');
        }
    }

    /** Devuelve conteos de las tablas principales para saber si la BD está limpia. */
    public function dbStatus(): JsonResponse
    {
        $counts = [
            'usuarios'         => User::count(),
            'empresas'         => Company::count(),
            'servicios'        => Service::count(),
            'facturas'         => Invoice::count(),
            'pagos'            => Payment::count(),
            'cargos recurrentes' => CompanyRecurringService::count(),
            'lotes inventario' => InventoryLot::count(),
            'movimientos inv.' => InventoryMovement::count(),
            'ventas inv.'      => InventorySale::count(),
            'alquileres inv.'  => InventoryRental::count(),
        ];

        $cleanable = array_sum(array_filter($counts, fn ($v, $k) => $k !== 'usuarios', ARRAY_FILTER_USE_BOTH));

        return response()->json([
            'is_clean' => $cleanable === 0,
            'counts'   => $counts,
        ]);
    }

    /** Limpia todas las tablas de negocio conservando usuarios. Requiere contraseña del admin. */
    public function wipe(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        if (! Hash::check($request->password, $request->user()->password)) {
            return response()->json(['message' => 'Contraseña incorrecta.'], 403);
        }

        $keep = [
            'users', 'personal_access_tokens', 'password_reset_tokens',
            'migrations', 'sessions', 'cache', 'cache_locks',
            'jobs', 'job_batches', 'failed_jobs',
        ];

        $tables  = Schema::getTableListing();
        $toWipe  = array_values(array_filter($tables, fn ($t) => ! in_array($t, $keep)));

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($toWipe as $table) {
            DB::table($table)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        return response()->json(['message' => 'Base de datos limpiada. Los usuarios se conservaron.']);
    }

    /** Dispara el comando db:backup y retorna el archivo generado. */
    public function trigger(): JsonResponse
    {
        $dir = $this->dir();
        $before = File::isDirectory($dir)
            ? collect(File::files($dir))->map(fn ($f) => basename((string) $f))->all()
            : [];

        $code = Artisan::call('db:backup', ['--force' => true]);

        if ($code !== 0) {
            return response()->json(
                ['message' => 'El respaldo falló. Verifique que mysqldump esté disponible en el servidor.'],
                500
            );
        }

        $after = File::isDirectory($dir)
            ? collect(File::files($dir))->map(fn ($f) => basename((string) $f))->all()
            : [];

        $new      = array_values(array_diff($after, $before));
        $filename = $new[0] ?? null;
        $size     = ($filename && File::exists($dir.DIRECTORY_SEPARATOR.$filename))
            ? File::size($dir.DIRECTORY_SEPARATOR.$filename)
            : null;

        return response()->json([
            'message'  => 'Respaldo generado correctamente.',
            'filename' => $filename,
            'size'     => $size,
        ]);
    }

    /** Lista los archivos de respaldo disponibles. */
    public function index(): JsonResponse
    {
        $dir = $this->dir();
        if (! File::isDirectory($dir)) {
            return response()->json(['backups' => []]);
        }

        $backups = collect(File::files($dir))
            ->filter(fn ($f) => in_array(pathinfo((string) $f, PATHINFO_EXTENSION), ['sql', 'sqlite']))
            ->sortByDesc(fn ($f) => @filemtime((string) $f))
            ->map(fn ($f) => [
                'filename'   => basename((string) $f),
                'size'       => File::size((string) $f),
                'created_at' => date('Y-m-d H:i:s', (int) @filemtime((string) $f)),
            ])
            ->values();

        return response()->json(['backups' => $backups]);
    }

    /** Descarga un archivo de respaldo por nombre. */
    public function download(string $filename): StreamedResponse
    {
        $this->guard($filename);
        $path = $this->dir().DIRECTORY_SEPARATOR.$filename;

        if (! File::exists($path)) {
            abort(404, 'Archivo no encontrado.');
        }

        return response()->streamDownload(function () use ($path) {
            readfile($path);
        }, $filename, [
            'Content-Type'   => 'application/octet-stream',
            'Content-Length' => (string) File::size($path),
        ]);
    }

    /** Elimina un archivo de respaldo. */
    public function destroy(string $filename): JsonResponse
    {
        $this->guard($filename);
        $path = $this->dir().DIRECTORY_SEPARATOR.$filename;

        if (! File::exists($path)) {
            abort(404, 'Archivo no encontrado.');
        }

        File::delete($path);

        return response()->json(['message' => 'Respaldo eliminado.']);
    }

    /** Restaura la base de datos desde un archivo SQL subido por el usuario. */
    public function restoreUpload(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:204800']]);

        $file = $request->file('file');

        if (! preg_match('/\.sql$/i', (string) $file->getClientOriginalName())) {
            return response()->json(['message' => 'El archivo debe tener extensión .sql.'], 422);
        }

        Artisan::call('db:backup', ['--force' => true]);

        $cfg = config('database.connections.mysql');

        $result = Process::command([
            'mysql',
            '--host='.$cfg['host'],
            '--port='.($cfg['port'] ?? 3306),
            '--user='.$cfg['username'],
            $cfg['database'],
        ])
            ->env(['MYSQL_PWD' => $cfg['password']])
            ->input(fopen($file->getRealPath(), 'r'))
            ->timeout(300)
            ->run();

        if (! $result->successful()) {
            \Log::error('AdminBackupController@restoreUpload failed', ['stderr' => $result->errorOutput()]);

            return response()->json(['message' => 'La restauración falló. Revise los logs del servidor para más detalles.'], 500);
        }

        return response()->json(['message' => 'Base de datos restaurada correctamente desde «'.$file->getClientOriginalName().'».']);
    }

    /** Restaura la base de datos desde un ZIP que contiene un archivo .sql. */
    public function restoreZip(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:512000']]);

        $uploaded = $request->file('file');

        if (! preg_match('/\.zip$/i', (string) $uploaded->getClientOriginalName())) {
            return response()->json(['message' => 'El archivo debe tener extensión .zip.'], 422);
        }

        $zip = new ZipArchive();
        if ($zip->open($uploaded->getRealPath()) !== true) {
            return response()->json(['message' => 'No se pudo abrir el archivo ZIP.'], 422);
        }

        $sql = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('/\.sql$/i', $name)) {
                $sql = $zip->getFromIndex($i);
                break;
            }
        }
        $zip->close();

        if ($sql === null || $sql === false) {
            return response()->json(['message' => 'El ZIP no contiene ningún archivo .sql.'], 422);
        }

        Artisan::call('db:backup', ['--force' => true]);

        $tmp = (string) tempnam(sys_get_temp_dir(), 'hbm_restore_');
        file_put_contents($tmp, $sql);

        $cfg = config('database.connections.mysql');

        $result = Process::command([
            'mysql',
            '--host='.$cfg['host'],
            '--port='.($cfg['port'] ?? 3306),
            '--user='.$cfg['username'],
            $cfg['database'],
        ])
            ->env(['MYSQL_PWD' => $cfg['password']])
            ->input(fopen($tmp, 'r'))
            ->timeout(300)
            ->run();

        @unlink($tmp);

        if (! $result->successful()) {
            \Log::error('AdminBackupController@restoreZip failed', ['stderr' => $result->errorOutput()]);

            return response()->json(['message' => 'La restauración falló. Revise los logs del servidor para más detalles.'], 500);
        }

        return response()->json(['message' => 'Base de datos restaurada correctamente desde el ZIP.']);
    }

    /** Restaura la base de datos desde un archivo SQL ya guardado en el servidor. */
    public function restore(string $filename): JsonResponse
    {
        $this->guard($filename);
        $path = $this->dir().DIRECTORY_SEPARATOR.$filename;

        if (! File::exists($path)) {
            abort(404, 'Archivo no encontrado.');
        }

        Artisan::call('db:backup', ['--force' => true]);

        $cfg = config('database.connections.mysql');

        $result = Process::command([
            'mysql',
            '--host='.$cfg['host'],
            '--port='.($cfg['port'] ?? 3306),
            '--user='.$cfg['username'],
            $cfg['database'],
        ])
            ->env(['MYSQL_PWD' => $cfg['password']])
            ->input(fopen($path, 'r'))
            ->timeout(300)
            ->run();

        if (! $result->successful()) {
            \Log::error('AdminBackupController@restore failed', ['file' => $filename, 'stderr' => $result->errorOutput()]);

            return response()->json(['message' => 'La restauración falló. Revise los logs del servidor para más detalles.'], 500);
        }

        return response()->json(['message' => "Base de datos restaurada desde «{$filename}»."]);
    }

    /** Genera y descarga un volcado SQL directamente (sin guardarlo en el servidor). */
    public function downloadSql(): StreamedResponse
    {
        $sql      = $this->generateSqlDump();
        $filename = 'respaldo-HBM-'.now()->format('Y-m-d-His').'.sql';

        return response()->streamDownload(function () use ($sql) {
            echo $sql;
        }, $filename, [
            'Content-Type'   => 'application/octet-stream',
            'Content-Length' => (string) strlen($sql),
        ]);
    }

    /** Genera el volcado SQL completo (para incluirlo en el ZIP de exportación). */
    private function generateSqlDump(): string
    {
        $cfg    = config('database.connections.mysql');
        $binary = (string) config('database.backup.mysqldump_binary', 'mysqldump');

        $result = Process::timeout(600)
            ->env(['MYSQL_PWD' => $cfg['password']])
            ->run([
                $binary,
                '--single-transaction',
                '--no-tablespaces',
                '-h', (string) ($cfg['host'] ?? '127.0.0.1'),
                '-P', (string) ($cfg['port'] ?? '3306'),
                '-u', (string) ($cfg['username'] ?? 'root'),
                (string) ($cfg['database'] ?? ''),
            ]);

        if (! $result->successful()) {
            abort(500, 'No se pudo generar el volcado SQL. Verifique que mysqldump esté disponible.');
        }

        return $result->output();
    }

    /** Exportación completa: SQL + servicios + facturas + inventario + info en un ZIP. */
    public function fullExport(): StreamedResponse
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'hbm_export_');
        $zip = new ZipArchive();

        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            abort(500, 'No se pudo crear el archivo ZIP.');
        }

        $zip->addFromString('database.sql', $this->generateSqlDump());
        $zip->addFromString('servicios.csv', $this->csvServices());
        $zip->addFromString('facturas.csv', $this->csvInvoices());
        $zip->addFromString('inventario.csv', $this->csvInventory());
        $zip->addFromString('info.json', $this->systemInfo());
        $zip->close();

        $filename = 'respaldo-HBM-'.now()->format('Y-m-d-His').'.zip';
        $size     = (int) filesize($tmp);

        return response()->streamDownload(function () use ($tmp) {
            readfile($tmp);
            @unlink($tmp);
        }, $filename, [
            'Content-Type'   => 'application/zip',
            'Content-Length' => (string) $size,
        ]);
    }

    private function csvServices(): string
    {
        $h = fopen('php://temp', 'w+');
        fwrite($h, "\xEF\xBB\xBF");
        fputcsv($h, ServiceRegistrySpreadsheetService::EXPORT_HEADERS, ';');

        Service::query()
            ->with(['company:id,nombre,nit', 'user:id,nombre', 'inventoryLot:id,name,sku'])
            ->orderByDesc('service_date')->orderByDesc('id')
            ->cursor()
            ->each(function ($svc) use ($h) {
                $lot = $svc->inventoryLot;
                fputcsv($h, [
                    $svc->service_date?->format('Y-m-d'),
                    $svc->company?->nombre ?? $svc->client_name,
                    $svc->company?->nit,
                    $svc->code,
                    (string) ($svc->kind ?? Service::KIND_SERVICIO),
                    $svc->inventory_lot_id !== null ? (string) $svc->inventory_lot_id : '',
                    $lot?->name ?? '',
                    $lot !== null ? (string) ($lot->internal_code ?? '') : '',
                    $svc->service_type,
                    $svc->description,
                    $svc->user?->nombre,
                    $svc->client_name,
                    (string) $svc->amount,
                    $svc->status,
                ], ';');
            });

        rewind($h);
        $csv = stream_get_contents($h);
        fclose($h);

        return (string) $csv;
    }

    private function csvInvoices(): string
    {
        $h = fopen('php://temp', 'w+');
        fwrite($h, "\xEF\xBB\xBF");
        fputcsv($h, [
            'Código', 'Empresa', 'NIT', 'Periodo', 'Estado',
            'Total', 'Total pagado', 'Saldo pendiente', 'Fecha creación',
        ], ';');

        Invoice::query()
            ->with('company:id,nombre,nit')
            ->withSum('payments', 'amount')
            ->whereNotNull('company_id')
            ->orderByDesc('period_year')->orderByDesc('period_month')->orderByDesc('id')
            ->cursor()
            ->each(function ($inv) use ($h) {
                $paid    = (float) ($inv->payments_sum_amount ?? 0);
                $total   = (float) $inv->total;
                $balance = max(0, $total - $paid);
                fputcsv($h, [
                    $inv->code,
                    $inv->company?->nombre ?? $inv->bill_to_nombre,
                    $inv->company?->nit ?? $inv->bill_to_nit,
                    $inv->period_month.'/'.$inv->period_year,
                    $inv->status,
                    number_format($total, 2, '.', ''),
                    number_format($paid, 2, '.', ''),
                    number_format($balance, 2, '.', ''),
                    $inv->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                ], ';');
            });

        rewind($h);
        $csv = stream_get_contents($h);
        fclose($h);

        return (string) $csv;
    }

    private function csvInventory(): string
    {
        $h = fopen('php://temp', 'w+');
        fwrite($h, "\xEF\xBB\xBF");
        fputcsv($h, [
            'ID', 'Nombre', 'SKU', 'Serial', 'Marca', 'Modelo',
            'Descripción', 'Etiqueta sitio', 'Código interno',
            'Estado ciclo', 'Cantidad disponible', 'Precio unitario',
            'Empresa tenant', 'Fecha creación',
        ], ';');

        InventoryLot::query()
            ->with('tenantCompany:id,nombre')
            ->orderByDesc('id')
            ->cursor()
            ->each(function ($lot) use ($h) {
                fputcsv($h, [
                    $lot->id,
                    $lot->name,
                    $lot->sku ?? '',
                    $lot->serial_number ?? '',
                    $lot->brand ?? '',
                    $lot->model ?? '',
                    $lot->description ?? '',
                    $lot->site_label ?? '',
                    $lot->internal_code ?? '',
                    $lot->lifecycle_status ?? 'activo',
                    $lot->quantity_available ?? '',
                    $lot->unit_price ?? '',
                    $lot->tenantCompany?->nombre ?? '',
                    $lot->created_at?->format('Y-m-d H:i'),
                ], ';');
            });

        rewind($h);
        $csv = stream_get_contents($h);
        fclose($h);

        return (string) $csv;
    }

    private function systemInfo(): string
    {
        return (string) json_encode([
            'exported_at'  => now()->toIso8601String(),
            'app_url'      => config('app.url'),
            'app_timezone' => config('app.timezone'),
            'totals'       => [
                'companies'      => Company::count(),
                'users'          => User::count(),
                'services'       => Service::count(),
                'invoices'       => Invoice::count(),
                'inventory_lots' => InventoryLot::count(),
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
