<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recuperación: si una migración anterior no llegó a crear estas tablas, se crean aquí.
 * Idempotente (Schema::hasTable).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('service_catalog_suggestions')) {
            Schema::create('service_catalog_suggestions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->string('name');
                $table->text('description')->nullable();
                $table->decimal('suggested_price', 14, 2);
                $table->string('status', 32)->default('pending')->index();
                $table->foreignId('resolved_catalog_id')->nullable()->constrained('service_catalog')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('service_items')) {
            Schema::create('service_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('service_id')->constrained()->cascadeOnDelete();
                $table->foreignId('catalog_id')->nullable()->constrained('service_catalog')->nullOnDelete();
                $table->foreignId('catalog_suggestion_id')->nullable()->constrained('service_catalog_suggestions')->nullOnDelete();
                $table->string('label');
                $table->text('line_description')->nullable();
                $table->decimal('amount', 14, 2);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // No eliminar: podrían haberse creado en la migración 2026_04_01_120000.
    }
};
