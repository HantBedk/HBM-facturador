<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('recurring_service_id')
                ->nullable()
                ->after('catalog_id')
                ->constrained('company_recurring_services')
                ->cascadeOnUpdate()
                ->nullOnDelete();
            $table->index(['company_id', 'recurring_service_id', 'service_date']);
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'recurring_service_id', 'service_date']);
            $table->dropConstrainedForeignId('recurring_service_id');
        });
    }
};
