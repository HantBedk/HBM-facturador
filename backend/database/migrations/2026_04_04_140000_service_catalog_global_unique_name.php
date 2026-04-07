<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dedupeCatalogNames();

        // MySQL: la FK sobre company_id requiere un índice; hay que quitar la FK antes del unique compuesto.
        Schema::table('service_catalog', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
        });

        Schema::table('service_catalog', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'name']);
        });

        Schema::table('service_catalog', function (Blueprint $table) {
            $table->dropColumn('company_id');
        });

        Schema::table('service_catalog', function (Blueprint $table) {
            $table->unique('name');
        });
    }

    /**
     * Garantiza nombres únicos por LOWER(TRIM(name)) antes de pasar a unique(name).
     * El primer registro por orden de id conserva el nombre; el resto recibe sufijo #id.
     */
    private function dedupeCatalogNames(): void
    {
        $rows = DB::table('service_catalog')->orderBy('id')->get(['id', 'name']);
        $seenNorms = [];

        foreach ($rows as $row) {
            $norm = mb_strtolower(trim((string) $row->name));
            if ($norm === '') {
                continue;
            }

            if (! isset($seenNorms[$norm])) {
                $seenNorms[$norm] = true;

                continue;
            }

            $suffix = ' #'.$row->id;
            $base = (string) $row->name;
            $maxLen = 255;
            $suffixLen = mb_strlen($suffix, 'UTF-8');
            $trimmedBase = mb_substr($base, 0, max(1, $maxLen - $suffixLen), 'UTF-8');
            $newName = $trimmedBase.$suffix;

            $attempt = 0;
            while ($this->catalogNameCollides($newName, (int) $row->id)) {
                $attempt++;
                $extra = ' ('.$attempt.')';
                $room = $maxLen - mb_strlen($suffix.$extra, 'UTF-8');
                $trimmedBase = mb_substr($base, 0, max(1, $room), 'UTF-8');
                $newName = $trimmedBase.$suffix.$extra;
            }

            DB::table('service_catalog')->where('id', $row->id)->update(['name' => $newName]);
            $seenNorms[mb_strtolower(trim($newName))] = true;
        }
    }

    private function catalogNameCollides(string $name, int $excludeId): bool
    {
        return DB::table('service_catalog')
            ->where('id', '!=', $excludeId)
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($name))])
            ->exists();
    }

    public function down(): void
    {
        throw new \RuntimeException('No reversible: el catálogo pasó a ámbito global sin company_id.');
    }
};
