<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('sku', 64)->nullable()->index();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->unsignedInteger('quantity_available');
            $table->decimal('unit_price', 14, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('inventory_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sold_by_user_id')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('notes', 500)->nullable();
            $table->decimal('total_amount', 14, 2);
            $table->timestamps();
        });

        Schema::create('inventory_sale_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_sale_id')->constrained('inventory_sales')->cascadeOnDelete();
            $table->foreignId('inventory_lot_id')->constrained('inventory_lots')->restrictOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->timestamps();
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_lot_id')->constrained('inventory_lots')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('type', 32);
            $table->integer('quantity_delta');
            $table->foreignId('inventory_sale_line_id')->nullable()->constrained('inventory_sale_lines')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_sale_lines');
        Schema::dropIfExists('inventory_sales');
        Schema::dropIfExists('inventory_lots');
    }
};
