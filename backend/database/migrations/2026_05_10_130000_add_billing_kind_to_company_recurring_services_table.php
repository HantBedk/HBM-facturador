<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_recurring_services', function (Blueprint $table) {
            if (! Schema::hasColumn('company_recurring_services', 'billing_kind')) {
                $table->string('billing_kind', 32)
                    ->default('servicio')
                    ->after('catalog_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('company_recurring_services', function (Blueprint $table) {
            if (Schema::hasColumn('company_recurring_services', 'billing_kind')) {
                $table->dropColumn('billing_kind');
            }
        });
    }
};
