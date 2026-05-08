<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_sales', function (Blueprint $table) {
            $table->timestamp('deleted_at')->nullable()->after('updated_at');
            $table->foreignId('invoice_id')->nullable()->after('deleted_at')->constrained('invoices')->nullOnDelete();
            $table->timestamp('voided_at')->nullable()->after('invoice_id');
            $table->foreignId('voided_by_user_id')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable()->after('voided_by_user_id');
        });

        Schema::table('inventory_rentals', function (Blueprint $table) {
            $table->timestamp('deleted_at')->nullable()->after('updated_at');
            $table->foreignId('invoice_id')->nullable()->after('deleted_at')->constrained('invoices')->nullOnDelete();
            $table->timestamp('voided_at')->nullable()->after('invoice_id');
            $table->foreignId('voided_by_user_id')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable()->after('voided_by_user_id');
        });

        DB::table('inventory_lots')
            ->where('decommission_reason', 'like', '%Agotado por venta%')
            ->update([
                'lifecycle_status' => 'vendido',
                'decommission_reason' => null,
                'decommissioned_at' => null,
                'decommissioned_by_user_id' => null,
            ]);
    }

    public function down(): void
    {
        Schema::table('inventory_sales', function (Blueprint $table) {
            $table->dropForeign(['invoice_id']);
            $table->dropForeign(['voided_by_user_id']);
            $table->dropColumn(['deleted_at', 'invoice_id', 'voided_at', 'voided_by_user_id', 'void_reason']);
        });

        Schema::table('inventory_rentals', function (Blueprint $table) {
            $table->dropForeign(['invoice_id']);
            $table->dropForeign(['voided_by_user_id']);
            $table->dropColumn(['deleted_at', 'invoice_id', 'voided_at', 'voided_by_user_id', 'void_reason']);
        });
    }
};
