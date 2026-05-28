<?php

use App\Models\InventoryLot;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_lots', function (Blueprint $table) {
            $table->boolean('allow_sale')->default(true)->after('is_active');
            $table->boolean('allow_rental')->default(false)->after('allow_sale');
        });

        $parse = static function (?string $description, array $keys, bool $default): bool {
            $text = trim((string) $description);
            if ($text === '') {
                return $default;
            }
            $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
            foreach ($lines as $line) {
                $raw = trim($line);
                if ($raw === '') {
                    continue;
                }
                $lower = mb_strtolower($raw);
                foreach ($keys as $key) {
                    if (! str_contains($lower, mb_strtolower($key))) {
                        continue;
                    }
                    $idx = strpos($raw, ':');
                    $after = $idx !== false ? trim(substr($raw, $idx + 1)) : '';
                    if ($after === '') {
                        return true;
                    }
                    $v = mb_strtolower($after);
                    if (str_contains($v, 'no') || $v === 'false' || $v === '0') {
                        return false;
                    }
                    if (str_contains($v, 'si') || str_contains($v, 'sí') || $v === 'true' || $v === '1') {
                        return true;
                    }

                    return true;
                }
            }

            return $default;
        };

        foreach (InventoryLot::query()->cursor() as $lot) {
            $desc = (string) ($lot->description ?? '');
            $allowSale = $parse($desc, ['Disponible para venta', 'Etiqueta para venta'], true);
            $allowRent = $parse($desc, ['Disponible para alquiler', 'Etiqueta para alquiler'], false);
            $lot->forceFill([
                'allow_sale' => $allowSale,
                'allow_rental' => $allowRent,
            ])->saveQuietly();
        }
    }

    public function down(): void
    {
        Schema::table('inventory_lots', function (Blueprint $table) {
            $table->dropColumn(['allow_sale', 'allow_rental']);
        });
    }
};
