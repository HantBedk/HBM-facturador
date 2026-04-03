<?php

use App\Support\CompanyFacturaSiglaAllocator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->char('factura_sigla', 3)->nullable()->unique()->after('nombre');
        });

        $used = [];
        foreach (DB::table('companies')->orderBy('id')->get() as $row) {
            $sigla = CompanyFacturaSiglaAllocator::allocate($row->nombre, (int) $row->id, $used);
            DB::table('companies')->where('id', $row->id)->update(['factura_sigla' => $sigla]);
        }
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('factura_sigla');
        });
    }
};
