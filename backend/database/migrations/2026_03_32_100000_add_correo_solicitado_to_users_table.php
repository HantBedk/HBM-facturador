<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('correo_solicitado', 255)->nullable()->after('correo');
            $table->timestamp('correo_solicitado_at')->nullable()->after('correo_solicitado');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['correo_solicitado', 'correo_solicitado_at']);
        });
    }
};
