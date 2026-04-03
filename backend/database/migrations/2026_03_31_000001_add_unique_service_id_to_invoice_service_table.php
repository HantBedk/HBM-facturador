<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un servicio solo puede estar en una factura (ETAPA 4).
     */
    public function up(): void
    {
        Schema::table('invoice_service', function (Blueprint $table) {
            $table->unique('service_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_service', function (Blueprint $table) {
            $table->dropUnique(['service_id']);
        });
    }
};
