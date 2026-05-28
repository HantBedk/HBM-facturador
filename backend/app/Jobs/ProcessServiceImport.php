<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\ServiceRegistrySpreadsheetService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Procesa una importación de servicios CSV en segundo plano.
 *
 * Flujo:
 *   1. El controlador guarda el archivo en storage/app/imports/ y lanza este job.
 *   2. El job ejecuta la importación y almacena el resultado en caché (TTL 1 hora).
 *   3. El frontend obtiene el resultado llamando a GET /admin/import/services/{jobId}/result.
 *   4. Opcionalmente el job despacha una notificación de panel al actor cuando termina.
 *
 * Nota: Actualmente el endpoint de importación es síncrono. Activar el flujo asíncrono
 * requiere que el controlador cambie a `ProcessServiceImport::dispatch(...)` y que
 * QUEUE_CONNECTION != sync en .env de producción.
 */
class ProcessServiceImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Reintentos automáticos ante fallos transitorios (BD bloqueada, timeout, etc.) */
    public int $tries = 3;

    /** Tiempo máximo de ejecución en segundos (5000 filas puede tomar ~60 s). */
    public int $timeout = 300;

    public function __construct(
        /** Ruta relativa dentro de storage/app/imports/ */
        public readonly string $storagePath,
        public readonly int $actorId,
        /** Clave de caché donde se almacenará el resultado para que el frontend lo lea. */
        public readonly string $resultCacheKey,
        public readonly bool $dryRun = false,
    ) {}

    public function handle(ServiceRegistrySpreadsheetService $importer): void
    {
        $fullPath = Storage::disk('local')->path($this->storagePath);

        try {
            $actor = User::findOrFail($this->actorId);
            $file = new UploadedFile($fullPath, basename($this->storagePath), null, null, true);

            $result = $importer->import($file, $actor, $this->dryRun);
            $result['status'] = 'completed';
            $result['dry_run'] = $this->dryRun;

            Cache::put($this->resultCacheKey, $result, now()->addHour());
        } catch (\Throwable $e) {
            Log::error('ProcessServiceImport falló', [
                'path' => $this->storagePath,
                'actor' => $this->actorId,
                'error' => $e->getMessage(),
            ]);

            Cache::put($this->resultCacheKey, [
                'status' => 'failed',
                'message' => $e->getMessage(),
            ], now()->addHour());

            throw $e;
        } finally {
            Storage::disk('local')->delete($this->storagePath);
        }
    }
}
