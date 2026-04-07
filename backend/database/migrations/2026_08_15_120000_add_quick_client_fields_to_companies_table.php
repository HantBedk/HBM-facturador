<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('es_cliente_puntual')->default(false)->after('correo');
            $table->string('telefono_normalizado', 32)->nullable()->after('es_cliente_puntual');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->unique('telefono_normalizado');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['telefono_normalizado']);
            $table->dropColumn(['es_cliente_puntual', 'telefono_normalizado']);
        });
    }
};
