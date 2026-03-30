<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('estado', 32)->default('activo')->after('nit');
            $table->string('telefono', 64)->nullable()->after('estado');
            $table->string('correo', 255)->nullable()->after('telefono');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['estado', 'telefono', 'correo']);
        });
    }
};
