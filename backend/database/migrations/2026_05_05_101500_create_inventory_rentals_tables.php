<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_rentals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('status', 24)->default('active');
            $table->string('customer_name', 190)->nullable();
            $table->string('customer_phone', 32)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'started_at'], 'inventory_rentals_status_started_idx');
        });

        Schema::create('inventory_rental_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_rental_id')->constrained('inventory_rentals')->cascadeOnDelete();
            $table->foreignId('inventory_lot_id')->constrained('inventory_lots')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->integer('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();
            $table->index(['inventory_lot_id', 'returned_at'], 'inventory_rental_lines_lot_returned_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_rental_lines');
        Schema::dropIfExists('inventory_rentals');
    }
};
