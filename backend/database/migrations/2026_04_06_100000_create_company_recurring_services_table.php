<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_recurring_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('catalog_id')
                ->nullable()
                ->constrained('service_catalog')
                ->cascadeOnUpdate()
                ->nullOnDelete();
            /** Título en factura (PDF); si vacío se usa el nombre del catálogo. */
            $table->string('service_type', 255)->nullable();
            $table->text('description')->nullable();
            $table->decimal('amount', 14, 2);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_recurring_services');
    }
};
