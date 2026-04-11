<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('invoices', 'bill_to_nombre')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->string('bill_to_nombre', 255)->nullable()->after('company_id');
                $table->string('bill_to_telefono', 64)->nullable()->after('bill_to_nombre');
                $table->string('bill_to_nit', 64)->nullable()->after('bill_to_telefono');
            });
        }

        if (! Schema::hasColumn('services', 'client_telefono')) {
            Schema::table('services', function (Blueprint $table) {
                $table->string('client_telefono', 64)->nullable()->after('client_name');
                $table->string('contact_phone_key', 32)->nullable()->after('client_telefono');
            });
        }

        // `es_cliente_puntual` se añade en una migración con timestamp posterior; sin esto falla el orden alfabético.
        $quickIds = Schema::hasColumn('companies', 'es_cliente_puntual')
            ? DB::table('companies')->where('es_cliente_puntual', true)->pluck('id')->all()
            : [];
        foreach ($quickIds as $cid) {
            $c = DB::table('companies')->where('id', $cid)->first();
            if ($c === null) {
                continue;
            }
            $key = $c->telefono_normalizado ?? preg_replace('/\D/', '', (string) $c->telefono);
            DB::table('services')->where('company_id', $cid)->update([
                'client_telefono' => $c->telefono,
                'contact_phone_key' => $key,
            ]);
            DB::table('invoices')->where('company_id', $cid)->update([
                'bill_to_nombre' => $c->nombre,
                'bill_to_telefono' => $c->telefono,
                'bill_to_nit' => $c->nit,
            ]);
        }

        // Hacer nullable ANTES de company_id = null (evita SQLSTATE 1048).
        Schema::table('services', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
        });
        Schema::table('services', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->change();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->change();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
        });

        if ($quickIds !== []) {
            DB::table('invoices')->whereIn('company_id', $quickIds)->update(['company_id' => null]);
            DB::table('services')->whereIn('company_id', $quickIds)->update(['company_id' => null]);
            DB::table('companies')->whereIn('id', $quickIds)->delete();
        }
    }

    public function down(): void
    {
        throw new \RuntimeException('Migración irreversible: datos de venta sin empresa y snapshots en facturas.');
    }
};
