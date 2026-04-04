<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_catalog', function (Blueprint $table) {
            $table->dropUnique(['name']);
        });

        Schema::table('service_catalog', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->unique(['company_id', 'name']);
        });

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

    public function down(): void
    {
        Schema::dropIfExists('service_items');
        Schema::dropIfExists('service_catalog_suggestions');

        Schema::table('service_catalog', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'name']);
        });

        Schema::table('service_catalog', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->unique('name');
        });
    }
};
