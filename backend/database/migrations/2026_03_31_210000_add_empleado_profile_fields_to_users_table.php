<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('telefono', 20)->nullable()->after('estado');
            $table->string('tipo_documento', 24)->nullable()->after('telefono');
            $table->string('numero_documento', 32)->nullable()->after('tipo_documento');
            $table->string('ciudad', 120)->nullable()->after('numero_documento');
            $table->string('departamento', 120)->nullable()->after('ciudad');
            $table->string('banco_codigo', 48)->nullable()->after('departamento');
            $table->string('cuenta_tipo', 16)->nullable()->after('banco_codigo');
            $table->string('cuenta_numero', 48)->nullable()->after('cuenta_tipo');
            $table->timestamp('perfil_completado_at')->nullable()->after('cuenta_numero');
        });

        $now = now();
        // No forzar onboarding a cuentas ya existentes (solo nuevos empleados quedarán en null).
        DB::table('users')->where('rol', 'empleado')->update(['perfil_completado_at' => $now]);
        DB::table('users')->whereIn('rol', ['admin', 'super_admin'])->update(['perfil_completado_at' => $now]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'telefono',
                'tipo_documento',
                'numero_documento',
                'ciudad',
                'departamento',
                'banco_codigo',
                'cuenta_tipo',
                'cuenta_numero',
                'perfil_completado_at',
            ]);
        });
    }
};
