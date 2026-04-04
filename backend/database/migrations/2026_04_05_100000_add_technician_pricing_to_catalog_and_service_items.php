<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_catalog', function (Blueprint $table) {
            $table->decimal('technician_discount_percent', 5, 2)->nullable()->after('base_price');
        });

        Schema::table('service_items', function (Blueprint $table) {
            $table->decimal('technician_line_amount', 14, 2)->nullable()->after('amount');
        });

        $global = 10.0;
        $row = DB::table('app_settings')->where('key', 'technician_catalog_discount_percent')->first();
        if ($row !== null && is_numeric($row->value)) {
            $g = (float) $row->value;
            if ($g > 0 && $g < 100) {
                $global = $g;
            }
        }

        $f = (100 - $global) / 100;

        DB::table('service_items')->orderBy('id')->chunkById(200, function ($rows) use ($f) {
            foreach ($rows as $r) {
                if ($r->technician_line_amount !== null) {
                    continue;
                }
                $amt = (float) $r->amount;
                if ($r->catalog_id !== null && $f > 0) {
                    $t = round($amt * $f, 2);
                } else {
                    $t = $amt;
                }
                DB::table('service_items')->where('id', $r->id)->update([
                    'technician_line_amount' => $t,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('service_items', function (Blueprint $table) {
            $table->dropColumn('technician_line_amount');
        });

        Schema::table('service_catalog', function (Blueprint $table) {
            $table->dropColumn('technician_discount_percent');
        });
    }
};
