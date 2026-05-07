<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_lots', function (Blueprint $table) {
            $table->foreignId('tenant_company_id')->nullable()->after('owner_user_id')
                ->constrained('companies')->cascadeOnUpdate()->nullOnDelete();
            $table->index(['tenant_company_id', 'is_active'], 'inventory_lots_tenant_active_idx');
        });

        Schema::table('inventory_sales', function (Blueprint $table) {
            $table->foreignId('tenant_company_id')->nullable()->after('sold_by_user_id')
                ->constrained('companies')->cascadeOnUpdate()->nullOnDelete();
            $table->index(['tenant_company_id', 'created_at'], 'inventory_sales_tenant_created_idx');
        });

        Schema::table('inventory_sale_lines', function (Blueprint $table) {
            $table->foreignId('tenant_company_id')->nullable()->after('owner_user_id')
                ->constrained('companies')->cascadeOnUpdate()->nullOnDelete();
            $table->index(['tenant_company_id', 'inventory_sale_id'], 'inventory_sale_lines_tenant_sale_idx');
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('tenant_company_id')->nullable()->after('user_id')
                ->constrained('companies')->cascadeOnUpdate()->nullOnDelete();
            $table->index(['tenant_company_id', 'created_at'], 'inventory_movements_tenant_created_idx');
        });

        Schema::table('inventory_rentals', function (Blueprint $table) {
            $table->foreignId('tenant_company_id')->nullable()->after('created_by_user_id')
                ->constrained('companies')->cascadeOnUpdate()->nullOnDelete();
            $table->index(['tenant_company_id', 'status'], 'inventory_rentals_tenant_status_idx');
        });

        Schema::table('inventory_rental_lines', function (Blueprint $table) {
            $table->foreignId('tenant_company_id')->nullable()->after('owner_user_id')
                ->constrained('companies')->cascadeOnUpdate()->nullOnDelete();
            $table->index(['tenant_company_id', 'inventory_rental_id'], 'inventory_rental_lines_tenant_rental_idx');
        });

        Schema::create('inventory_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_company_id')->nullable()->constrained('companies')->cascadeOnUpdate()->nullOnDelete();
            $table->string('entity_type', 64);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('action', 64);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->string('request_id', 120)->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['tenant_company_id', 'occurred_at'], 'inventory_audit_tenant_occurred_idx');
            $table->index(['entity_type', 'entity_id'], 'inventory_audit_entity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_audit_events');

        Schema::table('inventory_rental_lines', function (Blueprint $table) {
            $table->dropIndex('inventory_rental_lines_tenant_rental_idx');
            $table->dropConstrainedForeignId('tenant_company_id');
        });
        Schema::table('inventory_rentals', function (Blueprint $table) {
            $table->dropIndex('inventory_rentals_tenant_status_idx');
            $table->dropConstrainedForeignId('tenant_company_id');
        });
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropIndex('inventory_movements_tenant_created_idx');
            $table->dropConstrainedForeignId('tenant_company_id');
        });
        Schema::table('inventory_sale_lines', function (Blueprint $table) {
            $table->dropIndex('inventory_sale_lines_tenant_sale_idx');
            $table->dropConstrainedForeignId('tenant_company_id');
        });
        Schema::table('inventory_sales', function (Blueprint $table) {
            $table->dropIndex('inventory_sales_tenant_created_idx');
            $table->dropConstrainedForeignId('tenant_company_id');
        });
        Schema::table('inventory_lots', function (Blueprint $table) {
            $table->dropIndex('inventory_lots_tenant_active_idx');
            $table->dropConstrainedForeignId('tenant_company_id');
        });
    }
};
