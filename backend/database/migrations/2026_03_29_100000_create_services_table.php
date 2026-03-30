<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('client_name')->nullable();
            $table->string('service_type')->nullable();
            $table->text('description');
            $table->decimal('amount', 14, 2);
            $table->date('service_date');
            $table->string('status', 32);
            $table->timestamps();

            $table->index(['service_date', 'company_id']);
            $table->index(['user_id', 'service_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
