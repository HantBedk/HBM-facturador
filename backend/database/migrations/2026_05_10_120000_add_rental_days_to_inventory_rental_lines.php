<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_rental_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_rental_lines', 'rental_days')) {
                $table->unsignedInteger('rental_days')->default(1);
            }
        });
    }

    public function down(): void
    {
        Schema::table('inventory_rental_lines', function (Blueprint $table) {
            if (Schema::hasColumn('inventory_rental_lines', 'rental_days')) {
                $table->dropColumn('rental_days');
            }
        });
    }
};
