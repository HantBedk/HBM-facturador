<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            if (! Schema::hasColumn('services', 'kind')) {
                $table->string('kind', 32)->default('servicio')->after('catalog_id');
            }
            if (! Schema::hasColumn('services', 'inventory_lot_id')) {
                $table->foreignId('inventory_lot_id')->nullable()->after('company_id')
                    ->constrained('inventory_lots')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            if (Schema::hasColumn('services', 'inventory_lot_id')) {
                $table->dropConstrainedForeignId('inventory_lot_id');
            }
            if (Schema::hasColumn('services', 'kind')) {
                $table->dropColumn('kind');
            }
        });
    }
};

